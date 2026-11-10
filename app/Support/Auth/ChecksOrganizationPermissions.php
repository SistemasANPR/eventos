<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;

/**
 * Comprobación común de las Policies de la organización en contexto: exige
 * contexto, que el recurso sea de esa organización, membresía activa y alguno
 * de los permisos pedidos. Sin contexto, deniega.
 */
trait ChecksOrganizationPermissions
{
    /** @param  list<string>  $anyOf  basta con uno de estos permisos */
    private function allows(User $user, array $anyOf, ?string $organizationId = null): bool
    {
        if (! CurrentOrganization::has()) {
            return false;
        }

        $organization = CurrentOrganization::get();

        if ($organizationId !== null && $organizationId !== $organization->id) {
            return false;
        }

        if (! $user->hasActiveMembershipIn($organization)) {
            return false;
        }

        foreach ($anyOf as $permission) {
            if ($user->checkPermissionTo($permission)) {
                return true;
            }
        }

        return false;
    }
}
