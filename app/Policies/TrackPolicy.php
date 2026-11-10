<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Event;
use App\Models\Track;
use App\Models\User;
use App\Support\Auth\ChecksOrganizationPermissions;

/**
 * Quién puede qué con los ejes temáticos (plan 004, DA-3, CA-004-15): consultar
 * con `calls.view` o `tracks.manage`; crear, editar, ordenar, archivar y
 * eliminar con `tracks.manage`. Exige membresía activa en la organización del
 * eje o del evento. `delete` existe, pero la Action lo rechaza si el eje está en uso.
 */
final class TrackPolicy
{
    use ChecksOrganizationPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, ['calls.view', 'tracks.manage']);
    }

    public function view(User $user, Track $track): bool
    {
        return $this->allows($user, ['calls.view', 'tracks.manage'], $track->organization_id);
    }

    public function create(User $user, Event $event): bool
    {
        return $this->allows($user, ['tracks.manage'], $event->organization_id);
    }

    /** Ordenar los ejes de un evento. */
    public function reorder(User $user, Event $event): bool
    {
        return $this->allows($user, ['tracks.manage'], $event->organization_id);
    }

    public function update(User $user, Track $track): bool
    {
        return $this->allows($user, ['tracks.manage'], $track->organization_id);
    }

    public function delete(User $user, Track $track): bool
    {
        return $this->allows($user, ['tracks.manage'], $track->organization_id);
    }
}
