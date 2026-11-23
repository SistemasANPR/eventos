<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Enums\OrganizationRole;
use App\Exceptions\Domain\LastOrganizationAdmin;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;

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
        if (! self::anotherAdminExists($organization, $leaving)) {
            throw new LastOrganizationAdmin;
        }
    }

    /**
     * ¿Hay otro administrador activo, distinto de `$member`? Como `assertAnotherRemains`, exige la
     * organización en contexto (`CurrentOrganization::run`).
     */
    public static function anotherAdminExists(Organization $organization, User $member): bool
    {
        return User::query()
            ->activeMembersOf($organization)
            ->role(OrganizationRole::OrgAdmin->value)
            ->whereKeyNot($member->id)
            ->exists();
    }

    /**
     * ¿Alguna organización activa de `$member` tiene otro administrador que podría reiniciar su 2FA?
     * Entra a cada organización con su contexto; no requiere uno previo (plan 014, CA-014-5).
     */
    public static function anotherAdminExistsInAnyOrganizationOf(User $member): bool
    {
        foreach ($member->organizations()->wherePivot('status', 'active')->wherePivotNull('deactivated_at')->get() as $organization) {
            if (CurrentOrganization::run($organization, fn (): bool => self::anotherAdminExists($organization, $member))) {
                return true;
            }
        }

        return false;
    }
}
