<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Actions\Notifications\SendNotification;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\Submission;
use App\Models\SubmissionParticipant;
use App\Support\Notifications\MailRecipient;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Publica el resultado de UNA propuesta decidida y avisa al autor (plan 012, F3, H-012-3, CA-012-14, 15, 18
 * y 21): fija `results_published_at` y encola `submission.accepted`, `submission.rejected` o
 * `submission.waitlisted` por `SendNotification` (ADR-0009), nunca con `Mail::`. El correo no lleva enlace
 * de acceso: enlaza a «pedir mi enlace» (CA-012-14). Idempotente por propuesta y estado: la clave de
 * `SendNotification` es `clave:id de la propuesta`, así que un reintento o una segunda publicación no duplica
 * el correo, y una propuesta ya publicada se omite.
 *
 * No autoriza: la llama `PublishResults` (publicación manual de la convocatoria) y, cuando la convocatoria
 * ya publicó, las Actions de decisión. Requiere el contexto de organización y participa de la transacción
 * de quien la llama.
 */
final class PublishSubmissionResult
{
    /** Estados de resultado que se avisan al autor y su notificación del catálogo. */
    private const NOTIFICATIONS = [
        'accepted' => 'submission.accepted',
        'waitlisted' => 'submission.waitlisted',
        'rejected' => 'submission.rejected',
    ];

    public function __construct(private readonly SendNotification $notify) {}

    /**
     * @return bool verdadero solo si esta llamada publicó el resultado; falso si ya estaba publicado o la
     *              propuesta no está en un estado de resultado
     */
    public function handle(Submission $submission): bool
    {
        return DB::transaction(function () use ($submission): bool {
            $current = Submission::query()->lockForUpdate()->findOrFail($submission->id);
            $key = self::NOTIFICATIONS[$current->status->value] ?? null;

            if ($key === null || $current->results_published_at !== null) {
                return false;
            }

            $organization = CurrentOrganization::get();
            $event = Event::query()->findOrFail($current->event_id);
            $primary = SubmissionParticipant::query()
                ->where('submission_id', $current->id)
                ->where('is_primary', true)
                ->firstOrFail();

            $current->forceFill(['results_published_at' => now()])->save();

            $this->notify->handle(
                key: $key,
                recipient: new MailRecipient($primary->email, $primary->full_name, $current->locale),
                variables: [
                    'author_name' => $primary->full_name,
                    'event_name' => $event->getTranslation('name', $current->locale),
                    'submission_title' => $current->title,
                    'author_comment' => $this->commentFor($current),
                    'request_link_url' => route('author.request-link.show', ['organization' => $organization->slug, 'event' => $event->slug]),
                ],
                factId: $current->id,
                organization: $organization,
                eventId: $event->id,
            );

            // Solo ids y estado: el comentario y el correo del autor son datos personales.
            activity()
                ->performedOn($current)
                ->event('submission_result_published')
                ->withProperties([
                    'organization_id' => $current->organization_id,
                    'event_id' => $current->event_id,
                    'submission_id' => $current->id,
                    'status' => $current->status->value,
                ])
                ->log('submission_result_published');

            return true;
        });
    }

    /**
     * Tras decidir una propuesta (CA-012-21): si la convocatoria ya publicó sus resultados, el aviso sale al
     * decidir; si no, la decisión queda interna hasta la publicación (CA-012-20). Un fallo al avisar NO
     * deshace la decisión (CA-012-18): se registra, la propuesta queda sin publicar y una nueva publicación
     * de la convocatoria la reintenta.
     */
    public function afterDecision(Submission $submission): bool
    {
        $callId = $submission->call_for_submissions_id;
        $published = $callId !== null
            && CallForSubmissions::query()->whereKey($callId)->whereNotNull('results_published_at')->exists();

        if (! $published) {
            return false;
        }

        try {
            return $this->handle($submission);
        } catch (Throwable $exception) {
            report($exception);
            Log::error('No se pudo publicar el resultado de una propuesta al decidirla; la decisión se conserva.', [
                'submission_id' => $submission->id,
                'exception' => $exception::class,
            ]);

            return false;
        }
    }

    /** El comentario de la decisión, ya limpio, con su rótulo en el idioma del autor; vacío si no hay. */
    private function commentFor(Submission $submission): string
    {
        return $submission->author_comment === null || $submission->author_comment === ''
            ? ''
            : __('submission_results.author_comment_prefix', [], $submission->locale).' '.$submission->author_comment;
    }
}
