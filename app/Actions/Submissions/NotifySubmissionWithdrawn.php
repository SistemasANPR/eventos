<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Actions\Notifications\SendNotification;
use App\Models\Event;
use App\Models\Submission;
use App\Models\SubmissionParticipant;
use App\Support\Notifications\MailRecipient;
use App\Support\Submissions\SubmissionOrganizers;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Str;

/**
 * Encola `submission.withdrawn` al autor y al equipo con `submissions.view` (plan 006, CA-006-46), siempre
 * por `SendNotification` (ADR-0009). Idempotente por propuesta: retirar dos veces no repite el correo.
 *
 * La plantilla del catálogo habla del panel (`panel_url`). Al equipo se le da el enlace al panel; al autor,
 * que no tiene acceso al panel, la página «pedir mi enlace» del evento: nunca un enlace con acceso (DA-3).
 * Requiere el contexto de organización.
 */
final class NotifySubmissionWithdrawn
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

        $this->notify->handle(
            key: 'submission.withdrawn',
            recipient: new MailRecipient($primary->email, $primary->full_name, $submission->locale),
            variables: [
                'author_name' => $primary->full_name,
                'submission_title' => $submission->title,
                'event_name' => $event->getTranslation('name', $submission->locale),
                'panel_url' => route('author.request-link.show', ['organization' => $organization->slug, 'event' => $event->slug]),
            ],
            factId: $submission->id,
            organization: $organization,
            nonce: 'author',
            eventId: $event->id,
        );

        $panelUrl = route('filament.admin.resources.events.submissions.index', [
            'tenant' => $organization->slug,
            'event' => $event->id,
        ]);

        foreach (SubmissionOrganizers::current() as $organizer) {
            $this->notify->handle(
                key: 'submission.withdrawn',
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
