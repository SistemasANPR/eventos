<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Enums\SubmissionStatus;
use App\Exceptions\Domain\InvalidSubmissionTransition;
use App\Exceptions\Domain\PublishedDecisionNeedsConfirmation;
use App\Exceptions\Domain\SubmissionStatusConflict;
use App\Models\Submission;
use App\Models\SubmissionStatusChange;
use App\Models\User;
use App\Support\Submissions\SubmissionTransitions;
use Illuminate\Support\Facades\DB;

/**
 * Núcleo común de las transiciones de estado de una propuesta (plan 012, H-012-1): bloquea la fila,
 * comprueba el control de concurrencia y la tabla de transiciones, cambia el estado, añade el cambio al
 * historial y lo audita, todo en una transacción. No autoriza: lo hace cada Action de transición
 * (`AcceptSubmission`, `RevertSubmissionDecision`...), que además fija el contexto de organización. Solo
 * debe llamarse desde ellas.
 *
 * Concurrencia (CA-012-6): `$submission->status` es el estado con que quien decide cargó la pantalla; si
 * al bloquear la fila ya es otro, otra persona decidió antes y se lanza `SubmissionStatusConflict`.
 *
 * Una reversa (decidida → `under_review`) limpia el comentario para el autor y `results_published_at`:
 * el resultado anterior ya no vale y la nueva decisión se publicará de nuevo (DA-4). Si ese resultado ya
 * se había publicado, exige `$confirmedPublished` (CA-012-8). La reversa nunca envía correo.
 *
 * La auditoría lleva ids y estados, no el texto de los comentarios (datos personales, docs/privacidad.md).
 *
 * @throws SubmissionStatusConflict si el estado ya no es el que vio quien decide
 * @throws InvalidSubmissionTransition si la tabla de transiciones no la permite
 * @throws PublishedDecisionNeedsConfirmation si revierte un resultado publicado sin confirmarlo
 */
final class ChangeSubmissionStatus
{
    /**
     * @param  string|null  $authorComment  ya limpio (`AuthorComment::clean()`)
     * @param  string|null  $reason  motivo interno, ya limpio
     */
    public function handle(
        ?User $actor,
        Submission $submission,
        SubmissionStatus $to,
        ?string $authorComment = null,
        ?string $reason = null,
        bool $confirmedPublished = false,
        ?string $decisionBatchId = null,
    ): SubmissionStatusChange {
        return DB::transaction(function () use ($actor, $submission, $to, $authorComment, $reason, $confirmedPublished, $decisionBatchId): SubmissionStatusChange {
            $current = Submission::query()->lockForUpdate()->findOrFail($submission->id);
            $from = $current->status;

            if ($from !== $submission->status) {
                throw new SubmissionStatusConflict;
            }

            SubmissionTransitions::assertAllowed($from, $to);

            $reversal = SubmissionTransitions::isReversal($from, $to);
            $wasPublished = $current->results_published_at !== null;

            if ($reversal && $wasPublished && ! $confirmedPublished) {
                throw new PublishedDecisionNeedsConfirmation;
            }

            $current->forceFill([
                'status' => $to,
                'author_comment' => $authorComment,
                'results_published_at' => $reversal ? null : $current->results_published_at,
                'version' => $current->version + 1,
            ])->save();

            $change = new SubmissionStatusChange;
            $change->forceFill([
                'organization_id' => $current->organization_id,
                'event_id' => $current->event_id,
                'submission_id' => $current->id,
                'from_status' => $from,
                'to_status' => $to,
                'actor_user_id' => $actor?->id,
                'comment' => $reason,
                'author_comment' => $authorComment,
                'decision_batch_id' => $decisionBatchId,
            ])->save();

            activity()
                ->causedBy($actor)
                ->performedOn($current)
                ->event('submission_status_changed')
                ->withProperties([
                    'organization_id' => $current->organization_id,
                    'event_id' => $current->event_id,
                    'submission_id' => $current->id,
                    'change_id' => $change->id,
                    'from' => $from->value,
                    'to' => $to->value,
                    'reversal' => $reversal,
                    'was_published' => $wasPublished,
                    'has_author_comment' => $authorComment !== null,
                    'has_reason' => $reason !== null,
                    'decision_batch_id' => $decisionBatchId,
                ])
                ->log('submission_status_changed');

            return $change;
        });
    }
}
