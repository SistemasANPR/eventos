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
 * Cambia el rol de un miembro dentro de UNA organización (plan 001, CA-001-23).
 *
 * Solo toca los roles de `spatie/laravel-permission` de ese team: la membresía
 * no guarda rol y el rol que la persona tenga en otra organización no cambia.
 * Quien lo ejecuta debe ser miembro activo con `team.manage` en esa
 * organización. Deja un registro de auditoría con quién, a quién y de qué rol
 * a cuál.
 *
 * @throws AuthorizationException si quien cambia no puede gestionar el equipo
 * @throws MemberNotActive si el destinatario no es miembro activo
 * @throws LastOrganizationAdmin si quitaría el rol de administrador al último administrador activo
 */
final class ChangeMemberRole
{
    public function handle(User $actor, Organization $organization, User $member, OrganizationRole $role): void
    {
        CurrentOrganization::run($organization, function () use ($actor, $organization, $member, $role): void {
            // Los roles se cachean en el modelo por team: se recargan en este contexto.
            $actor->unsetRelation('roles')->unsetRelation('permissions');
            $member->unsetRelation('roles')->unsetRelation('permissions');

            if (! $actor->hasActiveMembershipIn($organization)) {
                throw new AuthorizationException;
            }
            Gate::forUser($actor)->authorize('team.manage');

            if (! $member->hasActiveMembershipIn($organization)) {
                throw new MemberNotActive;
            }

            $from = $member->getRoleNames()->sort()->values()->all();

            if ($from === [$role->value]) {
                return;
            }

            DB::transaction(function () use ($actor, $organization, $member, $role, $from): void {
                // Quitar el rol de administrador al último administrador activo dejaría la organización sin gestión (CA-003-25).
                if (in_array(OrganizationRole::OrgAdmin->value, $from, true) && $role !== OrganizationRole::OrgAdmin) {
                    OrganizationAdmins::lock($organization);
                    OrganizationAdmins::assertAnotherRemains($organization, $member);
                }

                $member->syncRoles([$role->value]);

                activity()
                    ->causedBy($actor)
                    ->performedOn($member)
                    ->event('role_changed')
                    ->withProperties(['organization_id' => $organization->id, 'from' => $from, 'to' => $role->value])
                    ->log('role_changed');
            });
        });
    }
}
