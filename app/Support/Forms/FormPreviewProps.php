<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Enums\FieldType;
use App\Models\Event;
use App\Models\FormDefinition;

/**
 * Datos que el panel entrega al `FormRenderer` de React para la vista previa (plan 005,
 * H-005-2): los campos vigentes en su orden, el idioma pedido y los textos de la interfaz
 * del formulario en ese idioma. Solo describe el formulario; la elección del texto de
 * cada campo (idioma pedido o, si falta, el base) la hace el renderizador, igual que
 * en el formulario público.
 */
final class FormPreviewProps
{
    /**
     * Vista previa del formulario que se está editando, con lo que hay en pantalla (aunque no esté guardado).
     * Es tolerante: lo incompleto se omite o se rellena para que la vista previa nunca falle; el servidor
     * valida de verdad al guardar.
     *
     * @param  array<array-key, mixed>  $state  campos del editor, tal como los tiene el formulario
     * @return array{fields: list<array<string, mixed>>, locale: string, defaultLocale: string, translations: array<string, mixed>}
     */
    public function fromState(array $state, Event $event): array
    {
        $fields = [];

        foreach ($state as $item) {
            $type = is_array($item) && is_string($item['type'] ?? null) ? FieldType::tryFrom($item['type']) : null;

            if ($type === null || ($item['retiredAt'] ?? null) !== null) {
                continue;
            }

            $fields[] = [
                'key' => is_string($item['key'] ?? null) && $item['key'] !== '' ? $item['key'] : 'preview_'.count($fields),
                'type' => $type->value,
                'required' => ($item['required'] ?? false) === true,
                'position' => count($fields) + 1,
                'identifiesAuthor' => false,
                'label' => self::texts($item['label'] ?? []),
                'help' => self::texts($item['help'] ?? []),
                'config' => self::config($item['config'] ?? []),
                'retiredAt' => null,
            ];
        }

        return [
            'fields' => $fields,
            'locale' => $event->default_locale,
            'defaultLocale' => $event->default_locale,
            'translations' => ['forms' => ['renderer' => trans('forms.renderer', [], $event->default_locale)]],
        ];
    }

    /** @return array<string, string> */
    private static function texts(mixed $texts): array
    {
        return is_array($texts)
            ? array_filter($texts, static fn (mixed $text): bool => is_string($text) && trim($text) !== '')
            : [];
    }

    /** @return array<string, mixed> */
    private static function config(mixed $config): array
    {
        $clean = [];

        foreach (is_array($config) ? $config : [] as $name => $value) {
            if ($name === 'options') {
                $clean['options'] = [];

                foreach (is_array($value) ? array_values($value) : [] as $index => $option) {
                    $label = self::texts(is_array($option) ? ($option['label'] ?? []) : []);

                    if ($label !== []) {
                        $clean['options'][] = ['value' => is_string($option['value'] ?? null) && $option['value'] !== '' ? $option['value'] : "option_{$index}", 'label' => $label];
                    }
                }

                continue;
            }

            if (is_numeric($value)) {
                $clean[$name] = $value + 0;
            } elseif (is_array($value) && $value !== []) {
                $clean[$name] = array_values($value);
            }
        }

        return $clean;
    }

    /**
     * @return array{fields: list<array<string, mixed>>, locale: string, defaultLocale: string, translations: array<string, mixed>}
     */
    public function handle(FormDefinition $form, Event $event, string $locale): array
    {
        $locale = in_array($locale, $event->locales, true) ? $locale : $event->default_locale;

        $fields = array_values(array_filter(
            $form->fields,
            static fn (array $field): bool => ($field['retiredAt'] ?? null) === null,
        ));
        usort($fields, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        return [
            'fields' => $fields,
            'locale' => $locale,
            'defaultLocale' => $event->default_locale,
            'translations' => ['forms' => ['renderer' => trans('forms.renderer', [], $locale)]],
        ];
    }
}
