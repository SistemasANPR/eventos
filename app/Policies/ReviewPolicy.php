<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\EventAssignmentRole;
use App\Enums\ReviewAssignmentStatus;
use App\Models\Event;
use App\Models\EventAssignment;
use App\Models\ReviewAssignment;
use App\Models\User;
use App\Support\Auth\ChecksOrganizationPermissions;
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
    use ChecksOrganizationPermissions;

    /**
     * Resultados del evento (plan 011, F6, CA-011-24): promedios, discrepancias y comentarios de los
     * evaluadores. Se llama como `Gate::allows('viewResults', [Review::class, $event])`; sin evento (`[Review::class]`) solo comprueba el permiso, para el menú.
     */
    public function viewResults(User $user, ?Event $event = null): bool
    {
        return $this->allows($user, ['reviews.view_results'], $event?->organization_id);
    }

    /** Avance por evaluador (CA-011-59): estados y fechas, nunca calificaciones ni comentarios. */
    public function viewProgress(User $user, ?Event $event = null): bool
    {
        return $this->allows($user, ['reviews.view_progress'], $event?->organization_id);
    }

    /** Cerrar y reabrir el periodo de evaluación del evento (CA-011-26 y 27). */
    public function closePeriod(User $user, ?Event $event = null): bool
    {
        return $this->allows($user, ['reviews.close_period'], $event?->organization_id);
    }

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

    /**
     * Declarar conflicto de interés (plan 011, CA-011-17): solo el dueño de una asignación vigente que aún no
     * se envió. Una evaluación ya enviada cuenta en los resultados: si el conflicto aparece después, el
     * organizador cancela la asignación (DA-7) y la evaluación se conserva sin contar.
     */
    public function declareConflict(User $user, ReviewAssignment $assignment): bool
    {
        return $this->ownsActiveAssignment($user, $assignment)
            && in_array($assignment->status, [ReviewAssignmentStatus::Pending, ReviewAssignmentStatus::InProgress], true);
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
