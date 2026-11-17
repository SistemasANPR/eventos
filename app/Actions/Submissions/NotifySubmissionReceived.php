<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Actions\Notifications\SendNotification;
use App\Models\Event;
use App\Models\Submission;
use App\Models\SubmissionParticipant;
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

        $this->notify->handle(
            key: 'submission.received',
            recipient: new MailRecipient($primary->email, $primary->full_name, $submission->locale),
            variables: [
                'author_name' => $primary->full_name,
                'submission_title' => $submission->title,
                'submission_reference' => SubmissionReference::for($submission),
                'event_name' => $eventName,
            ],
            factId: $submission->id,
            organization: $organization,
            eventId: $event->id,
        );

        $panelUrl = route('filament.admin.resources.events.submissions.index', [
            'tenant' => $organization->slug,
            'event' => $event->id,
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
