<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Enums\OrganizationRole;
use App\Exceptions\Domain\LastOrganizationAdmin;
use App\Models\Organization;
use App\Models\User;

/**
 * Regla del último administrador (plan 003, CA-003-25): una organización nunca
 * se queda sin un `org_admin` con membresía activa.
 *
 * Debe llamarse dentro de una transacción y con la organización en contexto
 * (`CurrentOrganization::run`), porque los roles se consultan por team. El
 * bloqueo de la fila de la organización serializa las bajas y cambios de rol
 * simultáneos: sin él, dos administradores que se quitan el acceso el uno al
 * otro a la vez podrían dejarla sin ninguno.
 */
final class OrganizationAdmins
{
    /** Bloquea la fila de la organización hasta el final de la transacción en curso. */
    public static function lock(Organization $organization): void
    {
        Organization::query()->whereKey($organization->id)->lockForUpdate()->sole();
    }

    /**
     * @throws LastOrganizationAdmin si `$leaving` es el único administrador activo
     */
    public static function assertAnotherRemains(Organization $organization, User $leaving): void
    {
        $others = User::query()
            ->activeMembersOf($organization)
            ->role(OrganizationRole::OrgAdmin->value)
            ->whereKeyNot($leaving->id)
            ->exists();

        if (! $others) {
            throw new LastOrganizationAdmin;
        }
    }
}
