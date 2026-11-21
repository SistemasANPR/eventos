<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Actions\Notifications\SendNotification;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\Submission;
use App\Models\SubmissionParticipant;
use App\Models\SubmissionType;
use App\Models\Track;
use App\Support\Notifications\MailRecipient;
use App\Support\Submissions\SubmissionOrganizers;
use App\Support\Submissions\SubmissionReference;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Str;

/**
 * Encola los correos de un envío recién confirmado (plan 006, CA-006-29), siempre por
 * `SendNotification` (ADR-0009): el acuse (`submission.received`) al participante principal, sin
 * enlace de acceso (DA-3), y el aviso (`submission.new_for_organizers`) a quienes tienen
 * `submissions.view` en la organización. Requiere el contexto de organización.
 *
 * Es idempotente por propuesta: `SendNotification` no duplica un hecho ya registrado. El aviso a
 * organizadores lleva como `nonce` a cada usuario, porque la clave de idempotencia no incluye al
 * destinatario y sin él solo recibiría el correo el primero.
 */
final class NotifySubmissionReceived
{
    public function __construct(private readonly SendNotification $notify) {}

    public function handle(Submission $submission): void
    {
        $organization = CurrentOrganization::get();
        $event = Event::query()->findOrFail($submission->event_id);
        $primary = SubmissionParticipant::query()
            ->where('submission_id', $submission->id)
            ->where('is_primary', true)
            ->firstOrFail();
        $eventName = $event->getTranslation('name', $submission->locale);
        $call = CallForSubmissions::query()->find($submission->call_for_submissions_id);
        $type = SubmissionType::query()->find($submission->submission_type_id);
        $track = Track::query()->find($submission->track_id);

        $this->notify->handle(
            key: 'submission.received',
            recipient: new MailRecipient($primary->email, $primary->full_name, $submission->locale),
            variables: [
                'author_name' => $primary->full_name,
                'submission_title' => $submission->title,
                'submission_reference' => SubmissionReference::for($submission),
                'event_name' => $eventName,
                // Datos de la convocatoria que el autor eligió (idioma de su envío; si falta, el del evento).
                'call_title' => (string) $call?->getTranslation('title', $submission->locale),
                'submission_type' => (string) $type?->getTranslation('name', $submission->locale),
                'track_name' => (string) $track?->getTranslation('name', $submission->locale),
            ],
            factId: $submission->id,
            organization: $organization,
            eventId: $event->id,
        );

        // La lista de propuestas es la sección `submissions` del evento (plan 020, `ManageEventSubmissions`).
        $panelUrl = route('filament.admin.resources.events.submissions', [
            'tenant' => $organization->slug,
            'record' => $event->id,
        ]);

        foreach (SubmissionOrganizers::current() as $organizer) {
            $this->notify->handle(
                key: 'submission.new_for_organizers',
                recipient: new MailRecipient($organizer->email, $organizer->name, $event->default_locale),
                variables: [
                    'author_name' => $primary->full_name,
                    'submission_title' => $submission->title,
                    'event_name' => $event->getTranslation('name', $event->default_locale),
                    'panel_url' => $panelUrl,
                ],
                factId: $submission->id,
                organization: $organization,
                nonce: Str::lower((string) $organizer->getKey()),
                eventId: $event->id,
            );
        }
    }
}
