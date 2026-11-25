<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Event;
use App\Models\Sponsor;
use App\Models\User;
use App\Support\Auth\ChecksOrganizationPermissions;

/**
 * Quién puede qué con los patrocinadores (plan 030, DA-2, CA-030-7): ver, crear y editar con `sponsors.manage`
 * (`org_admin` y `organizer`); eliminar con `sponsors.delete` (solo `org_admin`). El `reviewer` no ve nada.
 */
final class SponsorPolicy
{
    use ChecksOrganizationPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, ['sponsors.manage']);
    }

    public function view(User $user, Sponsor $sponsor): bool
    {
        return $this->allows($user, ['sponsors.manage'], $sponsor->organization_id);
    }

    public function create(User $user, Event $event): bool
    {
        return $this->allows($user, ['sponsors.manage'], $event->organization_id);
    }

    public function update(User $user, Sponsor $sponsor): bool
    {
        return $this->allows($user, ['sponsors.manage'], $sponsor->organization_id);
    }

    public function delete(User $user, Sponsor $sponsor): bool
    {
        return $this->allows($user, ['sponsors.delete'], $sponsor->organization_id);
    }
}
