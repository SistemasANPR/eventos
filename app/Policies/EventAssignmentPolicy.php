<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Event;
use App\Models\EventAssignment;
use App\Models\User;
use App\Support\Auth\ChecksOrganizationPermissions;

/**
 * Quién puede qué con las asignaciones de usuarios a eventos (plan 011,
 * H-011-7): todo se hace con `event_assignments.manage`. Exige membresía activa
 * en la organización en contexto y que el evento o la asignación sean de ella.
 */
final class EventAssignmentPolicy
{
    use ChecksOrganizationPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, ['event_assignments.manage']);
    }

    public function view(User $user, EventAssignment $assignment): bool
    {
        return $this->allows($user, ['event_assignments.manage'], $assignment->organization_id);
    }

    public function create(User $user, Event $event): bool
    {
        return $this->allows($user, ['event_assignments.manage'], $event->organization_id);
    }

    public function update(User $user, EventAssignment $assignment): bool
    {
        return $this->allows($user, ['event_assignments.manage'], $assignment->organization_id);
    }

    public function delete(User $user, EventAssignment $assignment): bool
    {
        return $this->allows($user, ['event_assignments.manage'], $assignment->organization_id);
    }
}
