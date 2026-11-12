<?php

declare(strict_types=1);

namespace App\Support\Forms;

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
