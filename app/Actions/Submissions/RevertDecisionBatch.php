<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Exceptions\Domain\PublishedDecisionNeedsConfirmation;
use App\Models\DecisionBatch;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\SubmissionStatusChange;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Submissions\AuthorComment;
use App\Support\Submissions\BatchReversalReport;
use App\Support\Submissions\SubmissionReference;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Revierte un lote de decisión masiva entero (plan 012, F2, H-012-2, DA-1, DA-4, CA-012-12 y 13). Exige
 * `submissions.revert` (la Policy `revertBatch`, no la interfaz) y se ejecuta en la organización del lote.
 *
 * Cada propuesta del lote vuelve a `under_review`, que es el destino de toda reversa (DA-3): la tabla de
 * transiciones no permite volver a `submitted`. Se hace con `RevertSubmissionDecision`, así que revertir una
 * aceptada oculta lo que pasó al programa (plan 021) y el historial conserva cada cambio.
 *
 * - Una propuesta cuyo estado ya no es el que dejó el lote (alguien la revirtió o la volvió a decidir
 *   después) NO se toca y el informe la lista como omitida con su motivo (CA-012-13). Por eso repetir la
 *   reversa de un lote ya revertido no cambia nada: todas salen omitidas.
 * - Si alguna propuesta por revertir ya tenía su resultado publicado, se exige `$confirmedPublished`
 *   (DA-4): el autor ya fue avisado y la reversa no envía correo. Sin confirmar, no cambia nada.
 * - Todo o nada: una sola transacción; un conflicto de concurrencia deshace la reversa entera.
 * - El motivo interno opcional queda en el historial de cada propuesta.
 *
 * @throws AuthorizationException si quien revierte no tiene `submissions.revert` en la organización del lote
 * @throws ValidationException si el motivo pasa de la longitud máxima
 * @throws PublishedDecisionNeedsConfirmation si hay resultados publicados y no se confirmó
 */
final class RevertDecisionBatch
{
    use AuthorizesInOrganization;

    public function __construct(private readonly RevertSubmissionDecision $revert) {}

    public function handle(User $actor, DecisionBatch $batch, ?string $reason = null, bool $confirmedPublished = false): BatchReversalReport
    {
        $reason = AuthorComment::clean($reason, 'reason');
        $organization = Organization::query()->findOrFail($batch->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $batch, $reason, $confirmedPublished): BatchReversalReport {
            $batch = DecisionBatch::query()->findOrFail($batch->id);
            $this->authorizeActor($actor, 'revertBatch', Submission::class, $batch);

            return DB::transaction(fn (): BatchReversalReport => $this->revertAll($actor, $batch, $reason, $confirmedPublished));
        });
    }

    private function revertAll(User $actor, DecisionBatch $batch, ?string $reason, bool $confirmedPublished): BatchReversalReport
    {
        $changes = SubmissionStatusChange::query()->where('decision_batch_id', $batch->id)->get();
        $submissions = Submission::query()
            ->whereIn('id', $changes->pluck('submission_id')->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $toRevert = [];
        $skipped = [];

        foreach ($changes as $change) {
            $submission = $submissions->get($change->submission_id);

            if ($submission instanceof Submission && $submission->status === $change->to_status) {
                $toRevert[] = $submission;

                continue;
            }

            if ($submission instanceof Submission) {
                $skipped[] = [
                    'reference' => SubmissionReference::for($submission),
                    'title' => $submission->title,
                    'reason' => BatchReversalReport::SKIP_STATUS_CHANGED,
                ];
            }
        }

        $wasPublished = array_filter($toRevert, static fn (Submission $submission): bool => $submission->results_published_at !== null) !== [];

        if ($wasPublished && ! $confirmedPublished) {
            throw new PublishedDecisionNeedsConfirmation;
        }

        $notices = [];

        foreach ($toRevert as $submission) {
            $notice = $this->revert->handle($actor, $submission, $reason, $confirmedPublished)->notice();

            if ($notice !== '') {
                $notices[] = $notice;
            }
        }

        activity()
            ->causedBy($actor)
            ->performedOn($batch)
            ->event('decision_batch_reverted')
            ->withProperties([
                'organization_id' => $batch->organization_id,
                'event_id' => $batch->event_id,
                'decision_batch_id' => $batch->id,
                'reverted' => count($toRevert),
                'skipped' => count($skipped),
                'was_published' => $wasPublished,
                'has_reason' => $reason !== null,
            ])
            ->log('decision_batch_reverted');

        return new BatchReversalReport(count($toRevert), $skipped, $notices);
    }
}
