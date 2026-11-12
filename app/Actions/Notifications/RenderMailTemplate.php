<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Exceptions\Domain\MailTemplateContextInvalid;
use App\Support\Notifications\NotificationCatalog;
use App\Support\Notifications\NotificationDescriptor;
use App\Support\Notifications\RenderedMail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Renderiza una plantilla de correo de forma segura (plan 008, DA-2 y DA-3).
 *
 * Orden obligatorio: primero el Markdown de la plantilla, con cada `{{ variable }}`
 * convertida en un marcador opaco; después se sustituyen los marcadores por los
 * valores ya escapados. Así un valor con `[x](https://evil)`, HTML o sintaxis
 * Markdown no puede crear enlaces, imágenes ni etiquetas. Nunca se ejecuta código:
 * no hay Blade ni PHP, y una variable fuera de la lista blanca se rechaza.
 *
 * @throws ValidationException si la plantilla usa una variable fuera de la lista blanca
 * @throws MailTemplateContextInvalid si falta el valor de una variable o una URL no es http(s)
 */
final class RenderMailTemplate
{
    private const PLACEHOLDER = '/\{\{(.*?)\}\}/s';

    private const CONTROL_CHARACTERS = '/[\x00-\x1F\x7F]+/';

    public function __construct(private readonly NotificationCatalog $catalog) {}

    /**
     * Comprueba que asunto y cuerpo solo usan variables de la lista blanca de la clave.
     * Se llama al guardar una plantilla (H-008-4) y al renderizar.
     *
     * @throws ValidationException
     */
    public function validate(string $key, string $subject, string $body): void
    {
        $allowed = $this->catalog->get($key)->variables;
        $errors = [];

        foreach (['subject' => $subject, 'body' => $body] as $field => $text) {
            $unknown = array_values(array_diff($this->placeholderNames($text), $allowed));

            if ($unknown !== []) {
                $errors[$field] = __('notifications.invalid_variable', ['variables' => implode(', ', $unknown)]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $variables  valores por nombre de variable
     * @param  string|null  $subject  plantilla propia; `null` usa la predeterminada del sistema
     * @param  string|null  $body  plantilla propia; `null` usa la predeterminada del sistema
     */
    public function handle(string $key, string $locale, array $variables, ?string $subject = null, ?string $body = null): RenderedMail
    {
        $descriptor = $this->catalog->get($key);
        $subject ??= (string) trans("mail_templates.{$key}.subject", [], $locale);
        $body ??= (string) trans("mail_templates.{$key}.body", [], $locale);

        $this->validate($key, $subject, $body);

        $values = $this->valuesFor($descriptor, $subject.$body, $variables);

        return $this->compose($subject, $body, $values);
    }

    /**
     * @param  array<string, string>  $values
     */
    private function compose(string $subject, string $body, array $values): RenderedMail
    {
        $singleLine = fn (string $value): string => trim((string) preg_replace(self::CONTROL_CHARACTERS, ' ', $value));

        $renderedSubject = $singleLine((string) preg_replace_callback(
            self::PLACEHOLDER,
            fn (array $match): string => $singleLine($values[trim($match[1])]),
            $subject,
        ));

        // Marcadores alfanuméricos y con un sufijo aleatorio por render: Markdown no los
        // altera y un valor no puede imitarlos.
        $nonce = bin2hex(random_bytes(6));
        $markers = [];
        $markdown = (string) preg_replace_callback(
            self::PLACEHOLDER,
            function (array $match) use ($nonce, &$markers): string {
                $name = trim($match[1]);

                return $markers[$name] ??= 'mvar'.$nonce.'x'.count($markers).'x';
            },
            $body,
        );

        $html = Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'renderer' => ['soft_break' => "<br />\n"],
        ]);

        $replacements = [];
        foreach ($markers as $name => $marker) {
            $replacements[$marker] = e($values[$name]);
        }

        $html = strtr($html, $replacements);

        return new RenderedMail($renderedSubject, $html, $this->toText($html));
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, string>
     */
    private function valuesFor(NotificationDescriptor $descriptor, string $templates, array $variables): array
    {
        $values = [];

        foreach ($this->placeholderNames($templates) as $name) {
            $value = $variables[$name] ?? null;

            if (! is_string($value) && ! is_int($value) && ! $value instanceof \Stringable) {
                throw MailTemplateContextInvalid::missingVariable($descriptor->key, $name);
            }

            $value = (string) $value;

            if (str_ends_with($name, '_url') && ! $this->isHttpUrl($value)) {
                throw MailTemplateContextInvalid::invalidUrl($descriptor->key, $name);
            }

            $values[$name] = $value;
        }

        return $values;
    }

    /** @return list<string> */
    private function placeholderNames(string $text): array
    {
        preg_match_all(self::PLACEHOLDER, $text, $matches);

        return array_values(array_unique(array_map('trim', $matches[1])));
    }

    private function isHttpUrl(string $value): bool
    {
        $scheme = parse_url($value, PHP_URL_SCHEME);

        return filter_var($value, FILTER_VALIDATE_URL) !== false
            && is_string($scheme)
            && in_array(strtolower($scheme), ['http', 'https'], true);
    }

    private function toText(string $html): string
    {
        $withBreaks = (string) preg_replace('#</(p|li|h[1-6]|blockquote)>#i', "\n\n", str_replace(['<br />', '<br>'], "\n", $html));

        return trim(html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
