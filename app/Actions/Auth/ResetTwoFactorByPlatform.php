<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Http\Requests\ResetTwoFactorRequest;
use App\Models\User;
use App\Notifications\TwoFactorResetNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Reinicia la 2FA de una cuenta desde la plataforma cuando no queda otro administrador que pueda
 * hacerlo (plan 014, CA-014-5). Exige registrar la verificación fuera de banda (el canal y una nota
 * de quién confirmó la identidad y a qué contacto previamente registrado, distinto del correo de la
 * cuenta): sin ese registro se deniega y nada cambia. La auditoría guarda quién, a quién, cuándo y
 * esa verificación; el titular recibe el aviso por correo.
 *
 * @param  array{verification_channel?: mixed, verification_note?: mixed}  $verification
 *
 * @throws AuthorizationException si quien actúa no es de plataforma, es la propia cuenta o aún existe otro administrador
 * @throws ValidationException si falta la verificación fuera de banda
 */
final class ResetTwoFactorByPlatform
{
    /** @param  array<string, mixed>  $verification */
    public function handle(User $actor, User $member, array $verification): void
    {
        Gate::forUser($actor)->authorize('resetTwoFactorAsPlatform', [User::class, $member]);

        /** @var array{verification_channel: string, verification_note: string} $verified */
        $verified = Validator::make($verification, ResetTwoFactorRequest::fieldRules())->validate();

        DB::transaction(function () use ($actor, $member, $verified): void {
            $member->clearTwoFactor();

            activity()
                ->causedBy($actor)
                ->performedOn($member)
                ->event('two_factor_reset')
                ->withProperties([
                    'by' => 'platform',
                    'verification_channel' => $verified['verification_channel'],
                    'verification_note' => $verified['verification_note'],
                ])
                ->log('two_factor_reset');

            $member->notify(new TwoFactorResetNotification(now()->toImmutable()));
        });
    }
}
