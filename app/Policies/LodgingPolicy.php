<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Event;
use App\Models\Lodging;
use App\Models\User;
use App\Support\Auth\ChecksOrganizationPermissions;

/**
 * Quién puede qué con el hospedaje (plan 030, DA-2, CA-030-7): ver, crear y editar con `lodgings.manage`
 * (`org_admin` y `organizer`); eliminar con `lodgings.delete` (solo `org_admin`). El `reviewer` no ve nada.
 */
final class LodgingPolicy
{
    use ChecksOrganizationPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, ['lodgings.manage']);
    }

    public function view(User $user, Lodging $lodging): bool
    {
        return $this->allows($user, ['lodgings.manage'], $lodging->organization_id);
    }

    public function create(User $user, Event $event): bool
    {
        return $this->allows($user, ['lodgings.manage'], $event->organization_id);
    }

    public function update(User $user, Lodging $lodging): bool
    {
        return $this->allows($user, ['lodgings.manage'], $lodging->organization_id);
    }

    public function delete(User $user, Lodging $lodging): bool
    {
        return $this->allows($user, ['lodgings.delete'], $lodging->organization_id);
    }
}
