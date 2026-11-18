<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Actions\Notifications\SendNotification;
use App\Enums\SubmissionStatus;
use App\Models\Event;
use App\Models\Submission;
use App\Models\SubmissionParticipant;
use App\Support\Notifications\MailRecipient;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;

/**
 * Envía el enlace de acceso a quien lo pide por correo (plan 006, H-006-3, CA-006-30 a 32).
 *
 * Por cada propuesta activa del evento cuyo participante PRINCIPAL tenga ese correo se crea un token
 * y se encola `submission.access_link`, con un máximo de `submissions.author.max_links_per_email`
 * (5). Sin propuestas no se crea nada. Nunca envía de forma síncrona: solo encola (ADR-0009).
 *
 * Quien llama NO debe revelar si hubo propuestas: devuelve cuántos enlaces se encolaron para que la
 * prueba lo compruebe, pero la respuesta HTTP es la misma en ambos casos (CA-006-32).
 * Cada solicitud usa como `nonce` el id del token: pedir otro enlace ES un hecho nuevo, y el límite
 * de peticiones por correo y el del catálogo (5 por hora) frenan el abuso.
 *
 * Exige el contexto de organización.
 */
final class SendAccessLink
{
    public function __construct(
        private readonly CreateAccessToken $createToken,
        private readonly SendNotification $notify,
    ) {}

    /** @return int enlaces encolados */
    public function handle(Event $event, string $email): int
    {
        $normalized = mb_strtolower(trim($email));
        $organization = CurrentOrganization::get();
        $sent = 0;

        foreach ($this->activeSubmissions($event, $normalized) as $submission) {
            $primary = SubmissionParticipant::query()
                ->where('submission_id', $submission->id)
                ->where('is_primary', true)
                ->firstOrFail();
            $token = $this->createToken->handle($submission);

            $this->notify->handle(
                key: 'submission.access_link',
                recipient: new MailRecipient($primary->email, $primary->full_name, $submission->locale),
                variables: [
                    'author_name' => $primary->full_name,
                    'event_name' => $event->getTranslation('name', $submission->locale),
                    'access_url' => route('author.access.show', [
                        'organization' => $organization->slug,
                        'event' => $event->slug,
                        'token' => $token->plain,
                    ]),
                    'expires_in_minutes' => (string) config('submissions.author.access_link_minutes'),
                ],
                factId: $submission->id,
                organization: $organization,
                nonce: $token->id,
                eventId: $event->id,
            );

            $sent++;
        }

        return $sent;
    }

    /** @return iterable<Submission> */
    private function activeSubmissions(Event $event, string $email): iterable
    {
        return Submission::query()
            ->where('event_id', $event->id)
            ->where('status', '!=', SubmissionStatus::Withdrawn->value)
            ->whereHas('participants', fn (Builder $participants) => $participants
                ->where('is_primary', true)
                ->whereRaw('lower(email) = ?', [$email]))
            ->latest('submitted_at')
            ->limit((int) config('submissions.author.max_links_per_email'))
            ->get();
    }
}
