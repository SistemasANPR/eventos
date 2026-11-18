<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

/**
 * Quién puede qué con las organizaciones desde el panel de plataforma (plan 016):
 * solo `is_platform_admin`. Se deniega por defecto y no se concede nada a los
 * roles de una organización. No hay `delete`: una organización no se elimina
 * desde el panel.
 */
final class OrganizationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_platform_admin;
    }

    public function view(User $user, Organization $organization): bool
    {
        return $user->is_platform_admin;
    }

    public function create(User $user): bool
    {
        return $user->is_platform_admin;
    }

    public function suspend(User $user, Organization $organization): bool
    {
        return $user->is_platform_admin;
    }

    public function reactivate(User $user, Organization $organization): bool
    {
        return $user->is_platform_admin;
    }
}
