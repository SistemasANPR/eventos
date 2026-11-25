<?php

declare(strict_types=1);

namespace App\Support\Calls;

use App\Enums\FormDefinitionStatus;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\FormDefinition;
use App\Models\Organization;
use App\Support\Forms\FormSettings;
use App\Support\Forms\ProposalFields;

/**
 * Datos de la convocatoria que la landing de la página pública arma por sí sola (sin contenido fijo en código):
 * el límite de personas, lo que pide el formulario publicado y las demás convocatorias del evento. Solo
 * nombres y etiquetas públicas: nunca identificadores internos ni datos del equipo.
 */
final class CallLandingData
{
    /** Cuántas convocatorias hermanas se anuncian como máximo al final de la página. */
    private const MAX_OTHER_CALLS = 3;

    /**
     * @return array{minParticipants: int, maxParticipants: int, formProposal: list<array{name: string, isRequired: bool}>, formParticipant: list<array{name: string, isRequired: bool}>, otherCalls: list<array{slug: string, title: string, description: ?string, url: string}>}
     */
    public static function from(CallForSubmissions $call, Event $event, Organization $organization, string $locale): array
    {
        $form = self::publishedForm($call);

        return [
            'minParticipants' => $call->min_participants,
            'maxParticipants' => $call->max_participants,
            'formProposal' => $form === null ? [] : self::proposalFields($form, $locale, $event->default_locale),
            'formParticipant' => $form === null ? [] : self::participantFields($form, $locale),
            'otherCalls' => self::otherCalls($call, $event, $organization, $locale),
        ];
    }

    private static function publishedForm(CallForSubmissions $call): ?FormDefinition
    {
        $form = $call->form_definition_id === null ? null : FormDefinition::query()->find($call->form_definition_id);

        return $form instanceof FormDefinition && $form->status === FormDefinitionStatus::Published && $form->event_id === $call->event_id ? $form : null;
    }

    /** @return list<array{name: string, isRequired: bool}> */
    private static function proposalFields(FormDefinition $form, string $locale, string $fallbackLocale): array
    {
        $fields = array_filter($form->fields, static fn (array $field): bool => ($field['retiredAt'] ?? null) === null);
        usort($fields, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);
        $rows = [];

        foreach ($fields as $field) {
            $label = is_array($field['label'] ?? null) ? ($field['label'][$locale] ?? $field['label'][$fallbackLocale] ?? '') : '';

            if (is_string($label) && $label !== '') {
                // El título y el tema siempre son obligatorios, igual que en el formulario.
                $rows[] = ['name' => $label, 'isRequired' => ($field['required'] ?? false) === true || ProposalFields::isRoleField($field)];
            }
        }

        return $rows;
    }

    /** @return list<array{name: string, isRequired: bool}> */
    private static function participantFields(FormDefinition $form, string $locale): array
    {
        $rows = [];

        foreach (FormSettings::participantFields($form->settings) as $key => $config) {
            if ($config['enabled']) {
                $rows[] = ['name' => (string) trans("public.submit.participant_{$key}", [], $locale), 'isRequired' => $config['required']];
            }
        }

        return $rows;
    }

    /** @return list<array{slug: string, title: string, description: ?string, url: string}> */
    private static function otherCalls(CallForSubmissions $call, Event $event, Organization $organization, string $locale): array
    {
        $others = $event->calls()->where('id', '!=', $call->id)->orderBy('opens_at')->get()
            // Un borrador no se anuncia (se trata como inexistente, igual que su propia página).
            ->filter(static fn (CallForSubmissions $other): bool => ResolveCallState::for($other) !== null)
            ->take(self::MAX_OTHER_CALLS);

        return $others->map(static fn (CallForSubmissions $other): array => [
            'slug' => $other->slug,
            'title' => self::text($other, 'title', $locale, $event->default_locale),
            'description' => self::text($other, 'description', $locale, $event->default_locale) ?: null,
            'url' => route('calls.show', ['organization' => $organization->slug, 'event' => $event->slug, 'call' => $other->slug], false)."?lang={$locale}",
        ])->values()->all();
    }

    private static function text(CallForSubmissions $call, string $attribute, string $locale, string $fallbackLocale): string
    {
        $text = (string) $call->getTranslation($attribute, $locale, false);

        return $text !== '' ? $text : (string) $call->getTranslation($attribute, $fallbackLocale, false);
    }
}
