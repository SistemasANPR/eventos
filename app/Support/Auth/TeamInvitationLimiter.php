<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Límite de envíos de invitación de equipo (plan 003, DA-7, CA-003-29):
 * 10 por minuto por usuario. El alta y el reenvío comparten la misma clave
 * (`team-invite:{id}`), de modo que reenviar no abre un segundo cupo.
 */
final class TeamInvitationLimiter
{
    public const MAX_PER_MINUTE = 10;

    /** @throws ThrottleRequestsException si el usuario superó el límite (429) */
    public static function hit(User $actor): void
    {
        $key = 'team-invite:'.$actor->id;

        // Se cuenta primero y se decide con el valor de `hit()`: contar después dejaría pasar una ráfaga paralela.
        if (RateLimiter::hit($key, 60) > self::MAX_PER_MINUTE) {
            throw new ThrottleRequestsException(headers: ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        }
    }
}
