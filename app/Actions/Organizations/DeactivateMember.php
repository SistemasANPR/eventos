<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Enums\OrganizationRole;
use App\Exceptions\Domain\LastOrganizationAdmin;
use App\Exceptions\Domain\MemberNotActive;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\OrganizationAdmins;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Desactiva la membresía de una persona en UNA organización (plan 003,
 * CA-003-24, -25, -27 y -28).
 *
 * No borra nada: fija `status` en `deactivated` y `deactivated_at`, y conserva
 * el usuario, sus roles y su historial. La persona pierde el acceso a esa
 * organización en la siguiente petición (`EnsureActiveMembership`); su acceso
 * a otras organizaciones no cambia. No se puede desactivar al último
 * administrador activo, ni siquiera a uno mismo. Deja un registro de auditoría.
 *
 * @throws AuthorizationException si quien la ejecuta no puede gestionar el equipo
 * @throws MemberNotActive si el destinatario no es miembro activo de la organización
 * @throws LastOrganizationAdmin si dejaría a la organización sin administrador activo
 */
final class DeactivateMember
{
    public function handle(User $actor, Organization $organization, User $member): void
    {
        CurrentOrganization::run($organization, function () use ($actor, $organization, $member): void {
            Gate::forUser($actor)->authorize('manage', User::class);

            DB::transaction(function () use ($actor, $organization, $member): void {
                OrganizationAdmins::lock($organization);

                // Los roles se cachean en el modelo por team: se recargan en este contexto.
                $member->unsetRelation('roles')->unsetRelation('permissions');

                if (! $member->hasActiveMembershipIn($organization)) {
                    throw new MemberNotActive;
                }

                if ($member->hasRole(OrganizationRole::OrgAdmin->value)) {
                    OrganizationAdmins::assertAnotherRemains($organization, $member);
                }

                $organization->users()->updateExistingPivot($member->id, [
                    'status' => 'deactivated',
                    'deactivated_at' => now(),
                ]);

                activity()
                    ->causedBy($actor)
                    ->performedOn($member)
                    ->event('member_deactivated')
                    ->withProperties(['organization_id' => $organization->id])
                    ->log('member_deactivated');
            });
        });
    }
}
