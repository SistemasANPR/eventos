<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\Auth\OrganizationAdmins;
use App\Support\Tenancy\CurrentOrganization;

/**
 * Gestión del equipo de la organización en contexto (plan 003, CA-003-24 y -26).
 * Se registra para el modelo `User`: el integrante es el sujeto de la acción.
 *
 * Quien actúa debe ser miembro activo de la organización en contexto y tener
 * `team.manage` en ella. Sin contexto de organización falla (no responde). El
 * destinatario de un cambio de rol o de una baja debe ser miembro activo de
 * esa misma organización: un usuario de otra organización nunca es destinatario.
 */
final class MembershipPolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->manage($actor);
    }

    public function create(User $actor): bool
    {
        return $this->manage($actor);
    }

    public function manage(User $actor): bool
    {
        $organization = CurrentOrganization::get();

        // Los roles se cachean en el modelo por team: se recargan en este contexto.
        $actor->unsetRelation('roles')->unsetRelation('permissions');

        return $actor->hasActiveMembershipIn($organization) && $actor->can('team.manage');
    }

    public function changeRole(User $actor, User $member): bool
    {
        return $this->manage($actor) && $member->hasActiveMembershipIn(CurrentOrganization::get());
    }

    public function deactivate(User $actor, User $member): bool
    {
        return $this->manage($actor) && $member->hasActiveMembershipIn(CurrentOrganization::get());
    }

    /** El destinatario es un integrante DESACTIVADO de esta organización (DA-10). */
    public function reactivate(User $actor, User $member): bool
    {
        return $this->manage($actor) && $member->hasDeactivatedMembershipIn(CurrentOrganization::get());
    }

    public function resendInvitation(User $actor, User $member): bool
    {
        return $this->manage($actor) && $member->hasActiveMembershipIn(CurrentOrganization::get());
    }

    /**
     * Reinicio de la 2FA por otro administrador (plan 014, CA-014-4): quien actúa tiene
     * `two_factor.reset` en la organización en contexto, el destinatario es miembro activo de ella y
     * nadie reinicia la suya (la propia 2FA no se desactiva).
     */
    public function resetTwoFactor(User $actor, User $member): bool
    {
        $organization = CurrentOrganization::get();

        // Los roles se cachean en el modelo por team: se recargan en este contexto.
        $actor->unsetRelation('roles')->unsetRelation('permissions');

        return $actor->isNot($member)
            && $actor->hasActiveMembershipIn($organization)
            && $actor->can('two_factor.reset')
            && $member->hasActiveMembershipIn($organization);
    }

    /**
     * Reinicio por la plataforma (plan 014, CA-014-5): solo cuando no queda otro administrador que
     * pueda hacerlo y nunca sobre la propia cuenta. No depende de una organización en contexto.
     */
    public function resetTwoFactorAsPlatform(User $actor, User $member): bool
    {
        return $actor->is_platform_admin
            && $actor->isNot($member)
            && ! OrganizationAdmins::anotherAdminExistsInAnyOrganizationOf($member);
    }
}
