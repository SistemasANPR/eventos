<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Actions\Submissions\MarkSubmissionUnderReview;
use App\Enums\ReviewAssignmentScope;
use App\Enums\ReviewAssignmentSkipReason;
use App\Enums\ReviewAssignmentSource;
use App\Enums\ReviewAssignmentStatus;
use App\Enums\SubmissionOrigin;
use App\Enums\SubmissionStatus;
use App\Models\Event;
use App\Models\ReviewAssignment;
use App\Models\Submission;
use App\Models\User;
use App\Support\Reviews\ReviewAssignmentReport;
use App\Support\Reviews\SkippedAssignment;
use Illuminate\Database\Eloquent\Collection;

/**
 * Escribe las asignaciones de unos evaluadores a unas propuestas ya elegidas (plan 011, H-011-2): el núcleo que
 * comparten `AssignReviewers` (a petición de una persona, `source = manual`) y `SyncScopedAssignments` (por una
 * regla de alcance, `source = scope`).
 *
 * NO autoriza, NO valida la entrada ni envía correo: quien lo llama ya autorizó, ya comprobó que cada evaluador
 * es integrante activo con rol de evento `reviewer` (CA-011-10) y decide si avisa (DA-8). Debe ejecutarse
 * dentro de una transacción, con las propuestas bloqueadas (`lockForUpdate`) y con sus participantes cargados,
 * en el contexto de la organización del evento.
 *
 * Omite sin fallar, y lo informa: propuestas invitadas o no elegibles (CA-011-40), parejas ya asignadas,
 * canceladas antes con evaluación conservada (DT-20) y parejas con correo coincidente (CA-011-7 y 41).
 * La primera asignación de una propuesta `submitted` la pasa a `under_review` (CA-011-9). Audita por propuesta.
 */
final class WriteReviewAssignments
{
    public function __construct(private readonly MarkSubmissionUnderReview $markUnderReview) {}

    /**
     * @param  Collection<int, Submission>  $submissions  con `participants` cargados y bloqueadas
     * @param  list<User>  $reviewers
     */
    public function handle(
        ?User $actor,
        Event $event,
        Collection $submissions,
        array $reviewers,
        ReviewAssignmentSource $source,
        ReviewAssignmentScope $scope,
        string $operationId,
    ): ReviewAssignmentReport {
        $existing = $this->existingAssignments($submissions, $reviewers);
        $assigned = [];
        $skipped = [];

        foreach ($submissions as $submission) {
            if ($submission->origin !== SubmissionOrigin::Call) {
                $skipped[] = new SkippedAssignment($submission->id, $submission->title, null, null, ReviewAssignmentSkipReason::NotFromCall);

                continue;
            }

            if (! in_array($submission->status, [SubmissionStatus::Submitted, SubmissionStatus::UnderReview], true)) {
                $skipped[] = new SkippedAssignment($submission->id, $submission->title, null, null, ReviewAssignmentSkipReason::IneligibleStatus);

                continue;
            }

            $participantEmails = $submission->participants->map(fn ($participant): string => $this->normalizeEmail($participant->email))->all();
            $created = [];

            foreach ($reviewers as $reviewer) {
                $current = $existing[$submission->id.'|'.$reviewer->id] ?? null;
                $reason = match (true) {
                    $current?->status === ReviewAssignmentStatus::Cancelled => ReviewAssignmentSkipReason::PreviouslyCancelled,
                    $current !== null => ReviewAssignmentSkipReason::AlreadyAssigned,
                    in_array($this->normalizeEmail($reviewer->email), $participantEmails, true) => ReviewAssignmentSkipReason::ConflictOfInterest,
                    default => null,
                };

                if ($reason !== null) {
                    $skipped[] = new SkippedAssignment($submission->id, $submission->title, $reviewer->id, $reviewer->name, $reason);

                    continue;
                }

                $assignment = new ReviewAssignment;
                $assignment->forceFill([
                    'event_id' => $event->id,
                    'submission_id' => $submission->id,
                    'reviewer_id' => $reviewer->id,
                    'status' => ReviewAssignmentStatus::Pending,
                    'assigned_by' => $actor?->id,
                    'assigned_at' => now(),
                    'source' => $source,
                ])->save();

                $created[] = $reviewer->id;
                $assigned[] = ['submission_id' => $submission->id, 'reviewer_id' => $reviewer->id];
            }

            if ($created === []) {
                continue;
            }

            // Su primera asignación la pasa a `under_review`; si ya lo estaba, no cambia nada (CA-011-9).
            $this->markUnderReview->handle($actor, $submission);

            activity()
                ->causedBy($actor)
                ->performedOn($submission)
                ->event('review_assignments_created')
                ->withProperties([
                    'organization_id' => $event->organization_id,
                    'event_id' => $event->id,
                    'operation_id' => $operationId,
                    'scope' => $scope->value,
                    'source' => $source->value,
                    'reviewer_ids' => $created,
                ])
                ->log('review_assignments_created');
        }

        return new ReviewAssignmentReport($operationId, $assigned, $skipped);
    }

    /**
     * @param  Collection<int, Submission>  $submissions
     * @param  list<User>  $reviewers
     * @return array<string, ReviewAssignment> por «propuesta|evaluador»
     */
    private function existingAssignments(Collection $submissions, array $reviewers): array
    {
        $existing = [];

        $assignments = ReviewAssignment::query()
            ->whereIn('submission_id', $submissions->modelKeys())
            ->whereIn('reviewer_id', array_map(static fn (User $reviewer): string => $reviewer->id, $reviewers))
            ->get();

        foreach ($assignments as $assignment) {
            $existing[$assignment->submission_id.'|'.$assignment->reviewer_id] = $assignment;
        }

        return $existing;
    }

    private function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
