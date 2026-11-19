<?php

declare(strict_types=1);

namespace App\Support\Landings;

use App\Enums\LandingBlockType;
use App\Support\Html\RichTextSanitizer;
use Closure;
use Illuminate\Support\Str;

/**
 * Reglas del contenido de cada tipo de bloque de la landing (plan 022, DA-3, DA-8, DA-14; CA-022-9 a 13).
 * La forma de cada tipo se declara UNA vez como esquema; de él salen la validación, el rechazo de
 * campos que el tipo no admite, la normalización (recorte, saneado del texto con formato, valores por
 * defecto) y la presentación al editor. Nada de lo que llega del cliente se guarda tal cual: solo
 * sobreviven los campos del esquema. La forma coincide con el borrador del editor
 * (`resources/js/types/landing.ts`, `LandingBlockDraft`): un bloque es `{id, type, ...campos}`.
 *
 * Tipos de campo del esquema:
 * - `text`: texto por idioma (`{es: "...", en: "..."}`), solo idiomas del evento; un idioma vacío es un
 *   texto faltante y no se guarda. Con `required`, el idioma base no puede faltar; con `requiredWhen`, solo
 *   si el campo hermano indicado tiene valor.
 * - `rich`: como `text`, pero con formato; se sanea con `RichTextSanitizer` al guardar.
 * - `enum`: una de varias opciones fijas (variantes sobre tokens, DA-15); sin valor, el predeterminado.
 * - `url`: enlace `https` externo (nunca `javascript:`, `data:` ni `http`); vacío es «sin enlace».
 * - `image`: referencia a una imagen de la landing, `{id}`; el `url` que trae el editor se ignora. La
 *   existencia de la imagen la comprueba la Action.
 * - `ignored`: lo que el cliente usa solo como clave de lista; se acepta y no se guarda.
 * - `list`: lista acotada de objetos.
 *
 * Esta clase es pura: no toca la base ni el disco.
 */
final class LandingBlockRules
{
    public const MAX_BLOCKS = 30;

    public const MAX_DRAFT_BYTES = 131_072;

    public const MAX_IMAGES = 20;

    public const MAX_GALLERY_IMAGES = 12;

    public const MAX_FAQ_ITEMS = 20;

    public const MAX_RICH_CHARACTERS = 5_000;

    /**
     * Esquema de los campos de un tipo de bloque (sin `id` ni `type`).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function schema(LandingBlockType $type): array
    {
        $title = ['kind' => 'text', 'max' => 120];

        return match ($type) {
            LandingBlockType::Hero => [
                'title' => ['kind' => 'text', 'max' => 160, 'required' => true],
                'subtitle' => ['kind' => 'text', 'max' => 300],
                'image' => ['kind' => 'image'],
                // Fondo decorativo: la alternativa es opcional (vacía = decorativa).
                'imageAlt' => ['kind' => 'text', 'max' => 160],
                'buttonUrl' => ['kind' => 'url'],
                'buttonLabel' => ['kind' => 'text', 'max' => 60, 'requiredWhen' => 'buttonUrl'],
                'tone' => ['kind' => 'enum', 'values' => ['light', 'accent'], 'default' => 'accent'],
                'align' => ['kind' => 'enum', 'values' => ['start', 'center'], 'default' => 'center'],
            ],
            LandingBlockType::RichText => [
                'html' => ['kind' => 'rich', 'max' => self::MAX_RICH_CHARACTERS, 'required' => true],
                'width' => ['kind' => 'enum', 'values' => ['narrow', 'wide'], 'default' => 'narrow'],
            ],
            LandingBlockType::EventInfo => [],
            LandingBlockType::Calls, LandingBlockType::Tracks => ['title' => $title],
            LandingBlockType::Faq => [
                'title' => $title,
                'items' => ['kind' => 'list', 'max' => self::MAX_FAQ_ITEMS, 'fields' => [
                    'key' => ['kind' => 'ignored'],
                    'question' => ['kind' => 'text', 'max' => 200, 'required' => true],
                    'answer' => ['kind' => 'rich', 'max' => self::MAX_RICH_CHARACTERS, 'required' => true],
                ]],
            ],
            LandingBlockType::ImageGallery => [
                'title' => $title,
                'images' => ['kind' => 'list', 'max' => self::MAX_GALLERY_IMAGES, 'fields' => [
                    'key' => ['kind' => 'ignored'],
                    'image' => ['kind' => 'image', 'required' => true],
                    'alt' => ['kind' => 'text', 'max' => 160, 'required' => true],
                ]],
            ],
            LandingBlockType::Spacer => [
                'size' => ['kind' => 'enum', 'values' => ['sm', 'md', 'lg'], 'default' => 'md'],
            ],
            LandingBlockType::Columns => [
                'columns' => ['kind' => 'list', 'exactly' => 2, 'fields' => [
                    'kind' => ['kind' => 'enum', 'values' => ['text', 'image'], 'default' => 'text'],
                    'html' => ['kind' => 'rich', 'max' => self::MAX_RICH_CHARACTERS],
                    'image' => ['kind' => 'image'],
                    'alt' => ['kind' => 'text', 'max' => 160, 'requiredWhen' => 'image'],
                ]],
            ],
        };
    }

    /**
     * Valida y normaliza los campos de un bloque. Devuelve el contenido limpio y los errores por campo
     * (ruta relativa al bloque, p. ej. `items.0.question.es`); con errores el contenido no sirve.
     *
     * @param  array<array-key, mixed>  $fields  el bloque sin `id` ni `type`
     * @param  list<string>  $locales  idiomas del evento (los únicos admitidos como clave de un texto)
     * @return array{content: array<string, mixed>, errors: array<string, string>}
     */
    public static function check(LandingBlockType $type, array $fields, array $locales, string $baseLocale): array
    {
        $errors = [];

        $clean = self::walk(self::schema($type), $fields, '', $locales, $baseLocale, $errors);

        return ['content' => $clean, 'errors' => $errors];
    }

    /**
     * Identificadores de imagen que referencia un contenido ya normalizado.
     *
     * @param  array<string, mixed>  $content
     * @return list<string>
     */
    public static function imageIds(LandingBlockType $type, array $content): array
    {
        return array_values(array_unique(self::collectImages(self::schema($type), $content)));
    }

    /**
     * El contenido guardado tal como lo edita el cliente (`LandingBlockDraft`): todo texto con una clave por
     * idioma del evento (vacía si falta), las imágenes con su URL de editor, los enlaces vacíos como cadena
     * vacía y cada elemento de lista con una clave de cliente.
     *
     * @param  array<string, mixed>  $content
     * @param  list<string>  $locales
     * @param  Closure(string): ?string  $imageUrl
     * @return array<string, mixed>
     */
    public static function present(LandingBlockType $type, array $content, array $locales, Closure $imageUrl, string $keyPrefix): array
    {
        return self::presentFields(self::schema($type), $content, $locales, $imageUrl, $keyPrefix);
    }

    /**
     * @param  array<string, array<string, mixed>>  $schema
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $locales
     * @param  Closure(string): ?string  $imageUrl
     * @return array<string, mixed>
     */
    private static function presentFields(array $schema, array $data, array $locales, Closure $imageUrl, string $keyPrefix): array
    {
        $out = [];

        foreach ($schema as $name => $field) {
            $value = $data[$name] ?? null;

            $out[$name] = match ($field['kind']) {
                'text', 'rich' => self::presentText($value, $locales),
                'enum' => (string) ($value ?? $field['default']),
                'url' => is_string($value) ? $value : '',
                'image' => self::presentImage($value, $imageUrl),
                'ignored' => $keyPrefix,
                default => self::presentList($field, $value, $locales, $imageUrl, "{$keyPrefix}-{$name}"),
            };
        }

        return $out;
    }

    /**
     * @param  Closure(string): ?string  $imageUrl
     * @return array{id: string, url: string}|null
     */
    private static function presentImage(mixed $value, Closure $imageUrl): ?array
    {
        if (! is_array($value) || ! is_string($value['id'] ?? null)) {
            return null;
        }

        $url = $imageUrl($value['id']);

        return $url === null ? null : ['id' => $value['id'], 'url' => $url];
    }

    /**
     * @param  list<string>  $locales
     * @return array<string, string>
     */
    private static function presentText(mixed $value, array $locales): array
    {
        $out = [];

        foreach ($locales as $locale) {
            $text = is_array($value) ? ($value[$locale] ?? '') : '';
            $out[$locale] = is_string($text) ? $text : '';
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  list<string>  $locales
     * @param  Closure(string): ?string  $imageUrl
     * @return list<array<string, mixed>>
     */
    private static function presentList(array $field, mixed $value, array $locales, Closure $imageUrl, string $keyPrefix): array
    {
        /** @var array<string, array<string, mixed>> $fields */
        $fields = $field['fields'];
        $items = [];

        foreach (is_array($value) ? array_values($value) : [] as $index => $item) {
            $presented = self::presentFields($fields, (array) $item, $locales, $imageUrl, "{$keyPrefix}-{$index}");

            // Una columna de texto o de imagen solo lleva sus propios campos (`DraftColumn`).
            if (isset($fields['kind'], $fields['html'])) {
                $presented = ($presented['kind'] ?? 'text') === 'image'
                    ? ['kind' => 'image', 'image' => $presented['image'], 'alt' => $presented['alt']]
                    : ['kind' => 'text', 'html' => $presented['html']];
            }

            $items[] = $presented;
        }

        return $items;
    }

    /**
     * @param  array<string, array<string, mixed>>  $schema
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $locales
     * @param  array<string, string>  $errors
     * @return array<string, mixed>
     */
    private static function walk(array $schema, array $data, string $path, array $locales, string $baseLocale, array &$errors): array
    {
        foreach (array_keys($data) as $key) {
            if (! is_string($key) || ! isset($schema[$key])) {
                $errors[self::join($path, (string) $key)] = self::message('landings.errors.unknown_field');
            }
        }

        $clean = [];

        foreach ($schema as $name => $field) {
            if ($field['kind'] === 'ignored') {
                continue;
            }

            $fieldPath = self::join($path, $name);
            $value = $data[$name] ?? null;
            $required = ($field['required'] ?? false) === true
                || (isset($field['requiredWhen']) && ($clean[$field['requiredWhen']] ?? null) !== null);

            $clean[$name] = match ($field['kind']) {
                'text' => self::text($value, $field, $required, false, $fieldPath, $locales, $baseLocale, $errors),
                'rich' => self::text($value, $field, $required, true, $fieldPath, $locales, $baseLocale, $errors),
                'enum' => self::option($value, $field, $fieldPath, $errors),
                'url' => self::url($value, $fieldPath, $errors),
                'image' => self::image($value, $required, $fieldPath, $errors),
                default => self::items($value, $field, $fieldPath, $locales, $baseLocale, $errors),
            };
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  list<string>  $locales
     * @param  array<string, string>  $errors
     * @return array<string, string>|null
     */
    private static function text(mixed $value, array $field, bool $required, bool $rich, string $path, array $locales, string $baseLocale, array &$errors): ?array
    {
        if ($value !== null && (! is_array($value) || ($value !== [] && array_is_list($value)))) {
            $errors[$path] = self::message('landings.errors.invalid');

            return null;
        }

        $clean = [];

        foreach ((array) $value as $locale => $text) {
            if (! is_string($locale) || ! in_array($locale, $locales, true)) {
                $errors[self::join($path, (string) $locale)] = self::message('landings.errors.locale_not_offered');

                continue;
            }

            if ($text === null) {
                continue;
            }

            if (! is_string($text)) {
                $errors[self::join($path, $locale)] = self::message('landings.errors.invalid');

                continue;
            }

            $text = trim($text);
            $max = (int) $field['max'];

            if (mb_strlen($text) > $max) {
                $errors[self::join($path, $locale)] = self::message('landings.errors.too_long', ['max' => $max]);

                continue;
            }

            $text = $rich ? RichTextSanitizer::clean($text) : $text;

            if ($text !== '') {
                $clean[$locale] = $text;
            }
        }

        if ($required && ! isset($clean[$baseLocale]) && ! isset($errors[self::join($path, $baseLocale)])) {
            $errors[self::join($path, $baseLocale)] = self::message('landings.errors.required');
        }

        return $clean === [] ? null : $clean;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, string>  $errors
     */
    private static function option(mixed $value, array $field, string $path, array &$errors): string
    {
        /** @var list<string> $values */
        $values = $field['values'];

        if ($value === null || $value === '') {
            return (string) $field['default'];
        }

        if (is_scalar($value) && in_array((string) $value, $values, true)) {
            return (string) $value;
        }

        $errors[$path] = self::message('landings.errors.invalid');

        return (string) $field['default'];
    }

    /** @param  array<string, string>  $errors */
    private static function url(mixed $value, string $path, array &$errors): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            $errors[$path] = self::message('landings.errors.invalid');

            return null;
        }

        $value = trim($value);
        $parts = parse_url($value);

        // Solo `https` con servidor, sin credenciales, espacios ni caracteres de control.
        $valid = strlen($value) <= 2048
            && is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && isset($parts['host']) && $parts['host'] !== ''
            && ! isset($parts['user'], $parts['pass'])
            && preg_match('/[\s\x00-\x1F\x7F]/', $value) !== 1
            && filter_var($value, FILTER_VALIDATE_URL) !== false;

        if (! $valid) {
            $errors[$path] = self::message('landings.errors.url_https');

            return null;
        }

        return $value;
    }

    /**
     * @param  array<string, string>  $errors
     * @return array{id: string}|null
     */
    private static function image(mixed $value, bool $required, string $path, array &$errors): ?array
    {
        if ($value === null) {
            if ($required) {
                $errors[$path] = self::message('landings.errors.required');
            }

            return null;
        }

        // El editor manda `{id, url}`: solo el identificador se conserva (la URL la construye el servidor).
        if (! is_array($value) || array_is_list($value) || ! is_string($value['id'] ?? null) || ! Str::isUuid($value['id'])
            || array_diff(array_keys($value), ['id', 'url']) !== []) {
            $errors[$path] = self::message('landings.errors.invalid');

            return null;
        }

        return ['id' => strtolower($value['id'])];
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  list<string>  $locales
     * @param  array<string, string>  $errors
     * @return list<array<string, mixed>>
     */
    private static function items(mixed $value, array $field, string $path, array $locales, string $baseLocale, array &$errors): array
    {
        $value ??= [];

        if (! is_array($value) || ! array_is_list($value)) {
            $errors[$path] = self::message('landings.errors.invalid');

            return [];
        }

        if (isset($field['exactly']) && count($value) !== $field['exactly']) {
            $errors[$path] = self::message('landings.errors.invalid');

            return [];
        }

        $max = isset($field['max']) ? (int) $field['max'] : count($value);

        if (count($value) > $max) {
            $errors[$path] = self::message('landings.errors.too_many', ['max' => $max]);

            return [];
        }

        /** @var array<string, array<string, mixed>> $fields */
        $fields = $field['fields'];
        $items = [];

        foreach ($value as $index => $item) {
            $itemPath = self::join($path, (string) $index);

            if (! is_array($item) || ($item !== [] && array_is_list($item))) {
                $errors[$itemPath] = self::message('landings.errors.invalid');

                continue;
            }

            $clean = self::walk($fields, $item, $itemPath, $locales, $baseLocale, $errors);

            // Una columna de texto necesita texto y una de imagen, imagen; de la otra clase no se guarda nada.
            if (isset($fields['kind'], $fields['html'])) {
                $clean = self::column($clean, $itemPath, $errors);
            }

            $items[] = $clean;
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $column
     * @param  array<string, string>  $errors
     * @return array<string, mixed>
     */
    private static function column(array $column, string $path, array &$errors): array
    {
        if ($column['kind'] === 'image') {
            if ($column['image'] === null && ! isset($errors[self::join($path, 'image')])) {
                $errors[self::join($path, 'image')] = self::message('landings.errors.required');
            }

            return ['kind' => 'image', 'image' => $column['image'], 'alt' => $column['alt']];
        }

        if ($column['html'] === null && ! isset($errors[self::join($path, 'html')])) {
            $errors[self::join($path, 'html')] = self::message('landings.errors.required');
        }

        return ['kind' => 'text', 'html' => $column['html']];
    }

    /**
     * @param  array<string, array<string, mixed>>  $schema
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    private static function collectImages(array $schema, array $data): array
    {
        $found = [];

        foreach ($schema as $name => $field) {
            $value = $data[$name] ?? null;

            if ($field['kind'] === 'image' && is_array($value) && is_string($value['id'] ?? null)) {
                $found[] = $value['id'];
            } elseif ($field['kind'] === 'list' && is_array($value)) {
                /** @var array<string, array<string, mixed>> $fields */
                $fields = $field['fields'];

                foreach ($value as $item) {
                    $found = [...$found, ...self::collectImages($fields, (array) $item)];
                }
            }
        }

        return $found;
    }

    /**
     * Mensaje traducido. `__()` puede devolver un arreglo si la clave apunta a un grupo; aquí siempre es una cadena.
     *
     * @param  array<string, int|string>  $replace
     */
    private static function message(string $key, array $replace = []): string
    {
        $message = __($key, $replace);

        return is_string($message) ? $message : $key;
    }

    private static function join(string $path, string $key): string
    {
        return $path === '' ? $key : "{$path}.{$key}";
    }
}
