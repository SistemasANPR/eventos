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
    /** Límite de personas que se muestra cuando el formulario no define uno (el de la convocatoria por defecto). */
    public const DEFAULT_MAX_PARTICIPANTS = 5;

    /**
     * Bloque de Participantes del editor de formularios («Así se ve»): lo que verá quien envíe la propuesta,
     * con el límite de personas que se está editando (aunque no esté guardado). Un valor ausente o fuera de
     * rango se muestra como el de por defecto; el servidor valida de verdad al guardar.
     *
     * @return array{locale: string, defaultLocale: string, maxParticipants: int, participantFields: array<string, array{enabled: bool, required: bool}>, translations: array<string, mixed>}
     */
    public function participants(Event $event, mixed $maxParticipants, mixed $settings = null): array
    {
        $max = is_numeric($maxParticipants) ? (int) $maxParticipants : self::DEFAULT_MAX_PARTICIPANTS;

        if ($max < 1 || $max > FormDefinitionRules::MAX_PARTICIPANTS) {
            $max = self::DEFAULT_MAX_PARTICIPANTS;
        }

        return [
            'locale' => $event->default_locale,
            'defaultLocale' => $event->default_locale,
            'maxParticipants' => $max,
            'participantFields' => FormSettings::participantFields(is_array($settings) ? $settings : null),
            'translations' => [
                'submit' => trans('public.submit', [], $event->default_locale),
                'forms' => ['renderer' => trans('forms.renderer', [], $event->default_locale)],
            ],
        ];
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
        // Un formulario anterior, sin título ni tema entre sus campos, los recibe al principio: el bloque fijo ya no existe.
        $fields = ProposalFields::withDefaults($fields, $event);
        usort($fields, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        return [
            // El título y el tema de la propuesta se envían con su nombre fijo (`s.title`, `s.track_id`).
            'fields' => ProposalFields::forRenderer($fields, $event, $locale),
            'locale' => $locale,
            'defaultLocale' => $event->default_locale,
            'translations' => ['forms' => ['renderer' => trans('forms.renderer', [], $locale)]],
        ];
    }
}
