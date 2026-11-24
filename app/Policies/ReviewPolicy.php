<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\EventAssignmentRole;
use App\Enums\ReviewAssignmentStatus;
use App\Models\EventAssignment;
use App\Models\ReviewAssignment;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;

/**
 * Quién ve y califica las propuestas asignadas (plan 011, H-011-3). A diferencia de las demás Policies de
 * la organización, NO se apoya en permisos de organizador (el rol `reviewer` no tiene ninguno, DA-1): se
 * apoya en que la asignación sea del propio usuario, en su membresía activa y en su asignación de evento
 * (`event_assignments`). Las habilidades reciben la asignación y `Review::class` es el modelo de la Policy:
 * `Gate::allows('submit', [Review::class, $assignment])`.
 *
 * - `viewAssignment`: ver la propuesta asignada; vale para todo estado salvo `cancelled`.
 * - `submit`: calificar o reevaluar; además la asignación no puede estar en conflicto declarado.
 *
 * El periodo cerrado no es asunto de la Policy: la Action lo informa con un error visible.
 */
final class ReviewPolicy
{
    /** Abrir el panel y la lista: membresía activa y asignación de evaluador en algún evento de la organización. */
    public function viewAnyAssignments(User $user): bool
    {
        return CurrentOrganization::has()
            && $user->hasActiveMembershipIn(CurrentOrganization::get())
            && EventAssignment::query()
                ->where('user_id', $user->id)
                ->where('role', EventAssignmentRole::Reviewer)
                ->exists();
    }

    public function viewAssignment(User $user, ReviewAssignment $assignment): bool
    {
        return $this->ownsActiveAssignment($user, $assignment);
    }

    public function submit(User $user, ReviewAssignment $assignment): bool
    {
        return $this->ownsActiveAssignment($user, $assignment)
            && $assignment->status !== ReviewAssignmentStatus::DeclinedConflict;
    }

    private function ownsActiveAssignment(User $user, ReviewAssignment $assignment): bool
    {
        if (! CurrentOrganization::has() || $assignment->organization_id !== CurrentOrganization::id()) {
            return false;
        }

        return $assignment->reviewer_id === $user->id
            && $assignment->status !== ReviewAssignmentStatus::Cancelled
            && $user->hasActiveMembershipIn(CurrentOrganization::get())
            && EventAssignment::query()
                ->where('event_id', $assignment->event_id)
                ->where('user_id', $user->id)
                ->where('role', EventAssignmentRole::Reviewer)
                ->exists();
    }
}
