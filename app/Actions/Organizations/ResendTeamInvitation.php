<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Exceptions\Domain\InvitationNotPending;
use App\Models\KnownDevice;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\SetPasswordNotification;
use App\Support\Auth\TeamInvitationLimiter;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Gate;

/**
 * Reenvía a un integrante el correo para fijar su contraseña (`team.set_password`)
 * cuando el alta falló al encolarlo, se perdió el correo o el enlace caducó
 * (plan 003, H-003-3; CA-003-21, -26, -28 y -29).
 *
 * - Autoriza con `MembershipPolicy::resendInvitation`: quien actúa gestiona el
 *   equipo y el destinatario es miembro ACTIVO de esta organización (un
 *   usuario de otra organización o desactivado nunca es destinatario).
 * - Comparte el límite de la alta (10 por minuto por usuario, `team-invite:{id}`).
 * - La invitación está «pendiente» mientras la persona nunca haya iniciado
 *   sesión, dato que ya registra `known_devices` (`RegisterKnownDevice`); no
 *   se añaden columnas. Quien ya inició sesión no necesita el enlace: usa
 *   «olvidé mi contraseña». Un dispositivo se registra solo si el inicio de
 *   sesión llegó a entrar al panel, así que quien fijó su contraseña pero no
 *   entró todavía puede recibir un reenvío inofensivo.
 * - El token se crea al construir el correo (`SetPasswordNotification`), no al
 *   encolar: nada secreto viaja en la cola ni en la auditoría.
 *
 * @throws AuthorizationException si quien actúa no puede gestionar el equipo o el destinatario no es miembro activo
 * @throws ThrottleRequestsException si superó el límite de envíos por minuto (429)
 * @throws InvitationNotPending si la persona ya inició sesión
 */
final class ResendTeamInvitation
{
    public function handle(User $actor, Organization $organization, User $member): void
    {
        CurrentOrganization::run($organization, function () use ($actor, $organization, $member): void {
            Gate::forUser($actor)->authorize('resendInvitation', $member);

            TeamInvitationLimiter::hit($actor);

            if (KnownDevice::query()->where('user_id', $member->id)->exists()) {
                throw new InvitationNotPending;
            }

            $member->notify(new SetPasswordNotification);

            activity()
                ->causedBy($actor)
                ->performedOn($member)
                ->event('member_invitation_resent')
                ->withProperties(['organization_id' => $organization->id])
                ->log('member_invitation_resent');
        });
    }
}
