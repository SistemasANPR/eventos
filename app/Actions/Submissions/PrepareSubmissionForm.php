<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Enums\FormDefinitionStatus;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\FormDefinition;
use App\Models\PrivacyNotice;
use App\Models\Track;
use App\Support\Forms\FormPreviewProps;
use App\Support\Forms\FormSettings;

/**
 * Datos del formulario público de una convocatoria ABIERTA (plan 006, H-006-7). Devuelve `null`
 * cuando la convocatoria no está lista: sin formulario publicado o sin aviso de privacidad en el
 * idioma pedido (CA-006-53, CA-006-55). Solo entonces se abre la sesión del formulario
 * (`IssueFormSession`), de modo que una página sin formulario no emite claves ni tokens.
 *
 * Nunca entrega la llave secreta de Turnstile: solo la pública (CA-006-23).
 */
final class PrepareSubmissionForm
{
    public function __construct(
        private readonly IssueFormSession $session,
        private readonly FormPreviewProps $fields,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function handle(CallForSubmissions $call, Event $event, string $locale, string $privacyUrl, string $uploadUrl): ?array
    {
        $form = $call->form_definition_id === null ? null : FormDefinition::query()->find($call->form_definition_id);
        $notice = PrivacyNotice::currentPublished($event);

        if (! $form instanceof FormDefinition
            || $form->status !== FormDefinitionStatus::Published
            || $form->event_id !== $call->event_id
            || $notice === null
            || trim((string) $notice->getTranslation('body', $locale, false)) === '') {
            return null;
        }

        $renderer = $this->fields->handle($form, $event, $locale);
        $session = $this->session->handle($call);

        return [
            'version' => $form->version,
            'fields' => $renderer['fields'],
            'defaultLocale' => $renderer['defaultLocale'],
            'translations' => $renderer['translations'],
            'idempotencyKey' => $session['idempotency_key'],
            'uploadToken' => $session['upload_token'],
            'uploadUrl' => $uploadUrl,
            'turnstileSiteKey' => VerifyTurnstile::siteKey(),
            ...$this->settings($form, $event, $locale),
            'minParticipants' => $call->min_participants,
            'maxParticipants' => $call->max_participants,
            'tracks' => $this->tracks($event, $locale),
            'privacyNotice' => ['id' => $notice->id, 'version' => $notice->version, 'url' => $privacyUrl],
        ];
    }

    /**
     * Ajustes del formulario que ve quien envía: preguntas de participantes, su posición y los dos títulos.
     *
     * @return array{participantFields: array<string, array{enabled: bool, required: bool}>, participantsPosition: string, title: ?string, extraSectionTitle: ?string}
     */
    private function settings(FormDefinition $form, Event $event, string $locale): array
    {
        return [
            'participantFields' => FormSettings::participantFields($form->settings),
            'participantsPosition' => FormSettings::position($form->settings),
            'title' => FormSettings::text($form->settings, 'title', $locale, $event->default_locale),
            'extraSectionTitle' => FormSettings::text($form->settings, 'extra_section_title', $locale, $event->default_locale),
        ];
    }

    /** @return list<array{id: string, name: string}> */
    private function tracks(Event $event, string $locale): array
    {
        return $event->tracks()->active()->orderBy('position')->get()
            ->map(fn (Track $track): array => ['id' => $track->id, 'name' => $track->label($locale)])
            ->values()
            ->all();
    }
}
