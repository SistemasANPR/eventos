<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Límites de intentos de acceso de 5 por 15 minutos (plan 001, DA-6).
 *
 * Cada límite es un cubo con su propia clave. Se cuentan todos los intentos,
 * no solo los fallidos, y el intento que rebasa el límite también se cuenta.
 * Si algún cubo se llena responde 429 y deja un registro de auditoría; los
 * registros nunca llevan correo ni contraseña en claro (solo el hash del
 * correo normalizado).
 */
final class AccessThrottle
{
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 900;

    /**
     * @param  list<string>  $buckets  claves de los límites que aplican a este intento
     * @param  array<string, string>  $context  datos para la auditoría (sin secretos)
     *
     * @throws ThrottleRequestsException si algún cubo está lleno (429)
     */
    public function hitOrFail(string $event, array $buckets, array $context): void
    {
        foreach ($buckets as $bucket) {
            // Se cuenta primero y se decide con el valor que devuelve `hit()`: comprobar
            // y luego contar dejaría pasar una ráfaga de peticiones paralelas, que
            // verían todas el contador por debajo del límite.
            if (RateLimiter::hit($bucket, self::DECAY_SECONDS) > self::MAX_ATTEMPTS) {
                activity('auth')->event($event)->withProperties($context)->log($event);

                throw new ThrottleRequestsException(
                    headers: ['Retry-After' => (string) RateLimiter::availableIn($bucket)],
                );
            }
        }
    }

    /** @param  list<string>  $buckets */
    public function clear(array $buckets): void
    {
        foreach ($buckets as $bucket) {
            RateLimiter::clear($bucket);
        }
    }

    /** Correo en minúsculas y sin espacios, para que variar mayúsculas no eluda el límite. */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function emailHash(string $email): string
    {
        return hash('sha256', self::normalizeEmail($email));
    }
}
