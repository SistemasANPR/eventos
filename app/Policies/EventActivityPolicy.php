<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Event;
use App\Models\EventActivity;
use App\Models\User;
use App\Support\Auth\ChecksOrganizationPermissions;

/**
 * Quién puede qué con las actividades del programa (plan 021, DA-13, CA-021-15): consultar, crear,
 * editar y publicar con `activities.manage` (`org_admin` y `organizer`); eliminar con
 * `activities.delete` (solo `org_admin`). Exige membresía activa en la organización de la actividad
 * o del evento.
 */
final class EventActivityPolicy
{
    use ChecksOrganizationPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, ['activities.manage']);
    }

    public function view(User $user, EventActivity $activity): bool
    {
        return $this->allows($user, ['activities.manage'], $activity->organization_id);
    }

    public function create(User $user, Event $event): bool
    {
        return $this->allows($user, ['activities.manage'], $event->organization_id);
    }

    public function update(User $user, EventActivity $activity): bool
    {
        return $this->allows($user, ['activities.manage'], $activity->organization_id);
    }

    public function delete(User $user, EventActivity $activity): bool
    {
        return $this->allows($user, ['activities.delete'], $activity->organization_id);
    }
}
