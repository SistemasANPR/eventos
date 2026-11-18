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
 * Encola `submission.updated` al equipo con `submissions.view` (plan 006, CA-006-41), siempre por
 * `SendNotification` (ADR-0009). La plantilla habla del panel, así que no se manda al autor (no tiene acceso
 * al panel). Cada edición es un hecho nuevo: el identificador del hecho incluye la versión de la propuesta,
 * y el de cada integrante va como `nonce`. Requiere el contexto de organización.
 */
final class NotifySubmissionUpdated
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

        $panelUrl = route('filament.admin.resources.events.submissions', [
            'tenant' => $organization->slug,
            'record' => $event->id,
        ]);

        foreach (SubmissionOrganizers::current() as $organizer) {
            $this->notify->handle(
                key: 'submission.updated',
                recipient: new MailRecipient($organizer->email, $organizer->name, $event->default_locale),
                variables: [
                    'author_name' => $primary->full_name,
                    'submission_title' => $submission->title,
                    'event_name' => $event->getTranslation('name', $event->default_locale),
                    'panel_url' => $panelUrl,
                ],
                factId: $submission->id.':v'.$submission->version,
                organization: $organization,
                nonce: Str::lower((string) $organizer->getKey()),
                eventId: $event->id,
            );
        }
    }
}
