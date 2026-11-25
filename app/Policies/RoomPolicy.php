<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Event;
use App\Models\Room;
use App\Models\User;
use App\Support\Auth\ChecksOrganizationPermissions;

/**
 * Quién puede qué con las salas de un evento (plan 031, CA-031-6): consultar, crear y editar (incluye
 * reordenar) con `activities.manage` (`org_admin` y `organizer`); eliminar con `activities.delete`
 * (solo `org_admin`). Son los permisos de las actividades: no hay permisos nuevos. Exige membresía
 * activa en la organización de la sala o del evento.
 */
final class RoomPolicy
{
    use ChecksOrganizationPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, ['activities.manage']);
    }

    public function view(User $user, Room $room): bool
    {
        return $this->allows($user, ['activities.manage'], $room->organization_id);
    }

    public function create(User $user, Event $event): bool
    {
        return $this->allows($user, ['activities.manage'], $event->organization_id);
    }

    public function update(User $user, Room $room): bool
    {
        return $this->allows($user, ['activities.manage'], $room->organization_id);
    }

    public function delete(User $user, Room $room): bool
    {
        return $this->allows($user, ['activities.delete'], $room->organization_id);
    }
}
