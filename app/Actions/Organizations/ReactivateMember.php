<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Exceptions\Domain\MemberNotDeactivated;
use App\Http\Requests\ChangeMemberRoleRequest;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\OrganizationAdmins;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Reactiva a un integrante desactivado de UNA organización (plan 003, DA-10;
 * CA-003-32 a -37).
 *
 * Es una decisión explícita del administrador: el rol que la persona tendrá se
 * confirma al reactivar (el formulario precarga el anterior) y queda como su
 * único rol en esa organización. Fija `status` en `active` y `deactivated_at`
 * en nulo; no toca las membresías ni los roles de otras organizaciones. Deja
 * un registro de auditoría con el rol anterior y el nuevo.
 *
 * @throws AuthorizationException si quien la ejecuta no puede gestionar el equipo
 * @throws ValidationException si el rol no es uno de los tres de la organización
 * @throws MemberNotDeactivated si el destinatario no es un integrante desactivado
 */
final class ReactivateMember
{
    public function handle(User $actor, Organization $organization, User $member, string $role): void
    {
        CurrentOrganization::run($organization, function () use ($actor, $organization, $member, $role): void {
            Gate::forUser($actor)->authorize('manage', User::class);

            /** @var array{role: string} $data */
            $data = Validator::make(['role' => $role], ChangeMemberRoleRequest::fieldRules())->validate();

            DB::transaction(function () use ($actor, $organization, $member, $data): void {
                OrganizationAdmins::lock($organization);

                // Los roles se cachean en el modelo por team: se recargan en este contexto.
                $member->unsetRelation('roles')->unsetRelation('permissions');

                if (! $member->hasDeactivatedMembershipIn($organization)) {
                    throw new MemberNotDeactivated;
                }

                // La desactivación conserva los roles (CA-003-27): este es el rol anterior.
                $from = $member->getRoleNames()->sort()->values()->all();

                $organization->users()->updateExistingPivot($member->id, [
                    'status' => 'active',
                    'deactivated_at' => null,
                ]);
                $member->syncRoles([$data['role']]);

                activity()
                    ->causedBy($actor)
                    ->performedOn($member)
                    ->event('member_reactivated')
                    ->withProperties(['organization_id' => $organization->id, 'from' => $from, 'to' => $data['role']])
                    ->log('member_reactivated');
            });
        });
    }
}
