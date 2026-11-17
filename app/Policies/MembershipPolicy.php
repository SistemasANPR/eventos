<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
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
}
