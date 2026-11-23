<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Event;
use App\Models\ReviewAssignment;
use App\Models\User;
use App\Support\Auth\ChecksOrganizationPermissions;

/**
 * Quién puede qué con las asignaciones de evaluadores (plan 011, H-011-2, CA-011-46): todo se hace con
 * `review_assignments.manage`. Exige membresía activa en la organización en contexto y que el evento o la
 * asignación sean de ella. Un evaluador no tiene ese permiso: lee las suyas por su panel (F4), no por aquí.
 */
final class ReviewAssignmentPolicy
{
    use ChecksOrganizationPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, ['review_assignments.manage']);
    }

    public function view(User $user, ReviewAssignment $assignment): bool
    {
        return $this->allows($user, ['review_assignments.manage'], $assignment->organization_id);
    }

    /** Asignar evaluadores en un evento (por propuesta, eje o convocatoria). */
    public function create(User $user, Event $event): bool
    {
        return $this->allows($user, ['review_assignments.manage'], $event->organization_id);
    }

    /** Quitar una asignación (se elimina o se cancela, DA-7). */
    public function delete(User $user, ReviewAssignment $assignment): bool
    {
        return $this->allows($user, ['review_assignments.manage'], $assignment->organization_id);
    }
}
