<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Enums\ReviewAssignmentRemoval;
use App\Enums\ReviewAssignmentStatus;
use App\Http\Requests\Reviews\RemoveReviewAssignmentRequest;
use App\Models\Event;
use App\Models\Organization;
use App\Models\ReviewAssignment;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Quita la asignación de un evaluador a una propuesta (plan 011, DA-7, CA-011-45 y 46). Se ejecuta en la
 * organización DEL EVENTO.
 *
 * - `pending`: se elimina.
 * - `in_progress` o `submitted`: pasa a `cancelled`; la evaluación se conserva y deja de contar en los
 *   resultados. También `declined_conflict`: la declaración de conflicto es evidencia y no se borra, y
 *   la cancelada impide reasignar la misma pareja (`AssignReviewers` la omite).
 * - Ya `cancelled`: no cambia nada (idempotente).
 *
 * Quitar no devuelve la propuesta a `submitted` ni envía correo. Deja auditoría. Autoriza con la Policy
 * antes de buscar la asignación, para no revelar con un 404 qué identificadores existen.
 *
 * @throws AuthorizationException si quien quita no tiene `review_assignments.manage` en la organización del evento
 * @throws ValidationException si el identificador no tiene forma de UUID
 * @throws ModelNotFoundException si la asignación no es del evento (de otro evento, otra organización o inexistente)
 */
final class RemoveReviewAssignment
{
    use AuthorizesInOrganization;

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, Event $event, array $data): ReviewAssignmentRemoval
    {
        $organization = Organization::query()->findOrFail($event->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $event, $data): ReviewAssignmentRemoval {
            $this->authorizeActor($actor, 'create', ReviewAssignment::class, $event);

            $input = Validator::make($data, (new RemoveReviewAssignmentRequest)->rules())->validate();

            return DB::transaction(function () use ($actor, $event, $input): ReviewAssignmentRemoval {
                $assignment = ReviewAssignment::query()
                    ->where('event_id', $event->id)
                    ->lockForUpdate()
                    ->findOrFail($input['assignment_id']);

                $this->authorizeActor($actor, 'delete', $assignment);

                if ($assignment->status === ReviewAssignmentStatus::Cancelled) {
                    return ReviewAssignmentRemoval::Cancelled;
                }

                $previous = $assignment->status;
                $outcome = $previous === ReviewAssignmentStatus::Pending ? ReviewAssignmentRemoval::Deleted : ReviewAssignmentRemoval::Cancelled;

                if ($outcome === ReviewAssignmentRemoval::Deleted) {
                    $assignment->delete();
                } else {
                    $assignment->forceFill(['status' => ReviewAssignmentStatus::Cancelled])->save();
                }

                activity()
                    ->causedBy($actor)
                    ->performedOn($assignment)
                    ->event('review_assignment_removed')
                    ->withProperties([
                        'organization_id' => $event->organization_id,
                        'event_id' => $event->id,
                        'submission_id' => $assignment->submission_id,
                        'reviewer_id' => $assignment->reviewer_id,
                        'old' => ['status' => $previous->value],
                        'new' => ['outcome' => $outcome->value],
                    ])
                    ->log('review_assignment_removed');

                return $outcome;
            });
        });
    }
}
