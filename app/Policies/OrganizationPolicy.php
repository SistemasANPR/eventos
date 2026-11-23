<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;

/**
 * Quién puede qué con las organizaciones desde el panel de plataforma (plan 016):
 * solo `is_platform_admin`. Se deniega por defecto y no se concede nada a los
 * roles de una organización, salvo `update` (plan 028): el administrador de la
 * propia organización cambia sus datos. No hay `delete`: una organización no se
 * elimina desde el panel.
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

    /** Miembro activo de ESA organización con `team.manage` (plan 028, CA-028-3 y -4). */
    public function update(User $user, Organization $organization): bool
    {
        return CurrentOrganization::run($organization, function () use ($user, $organization): bool {
            // Los roles se cachean en el modelo por team: se recargan en este contexto.
            $user->unsetRelation('roles')->unsetRelation('permissions');

            return $user->hasActiveMembershipIn($organization) && $user->can('team.manage');
        });
    }
}
