<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\Organization;
use App\Models\User;
use App\Notifications\TwoFactorResetNotification;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Reinicia la verificación en dos pasos de un integrante de la organización (plan 014, CA-014-4).
 *
 * Quien actúa es otro administrador con `two_factor.reset` en esa organización; nadie reinicia la
 * suya (la propia 2FA no se desactiva). Borra el secreto y los códigos de recuperación, de modo que
 * en su siguiente acceso la persona debe configurarla de nuevo. Deja un registro de auditoría con
 * quién, a quién y cuándo (sin secretos) y avisa por correo al titular.
 *
 * @throws AuthorizationException si quien actúa no puede reiniciarla (o es su propia cuenta)
 */
final class ResetTwoFactor
{
    public function handle(User $actor, Organization $organization, User $member): void
    {
        CurrentOrganization::run($organization, function () use ($actor, $organization, $member): void {
            Gate::forUser($actor)->authorize('resetTwoFactor', [User::class, $member]);

            DB::transaction(function () use ($actor, $organization, $member): void {
                $member->clearTwoFactor();

                activity()
                    ->causedBy($actor)
                    ->performedOn($member)
                    ->event('two_factor_reset')
                    ->withProperties(['organization_id' => $organization->id, 'by' => 'organization_admin'])
                    ->log('two_factor_reset');

                $member->notify(new TwoFactorResetNotification(now()->toImmutable()));
            });
        });
    }
}
