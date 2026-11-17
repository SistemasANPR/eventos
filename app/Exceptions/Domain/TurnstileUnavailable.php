<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * El servicio anti-robots no respondió a tiempo o falló (plan 006, DA-4 y CA-006-22).
 * Se cierra el acceso: aceptar sin verificar abriría la puerta al spam. Invita a reintentar.
 */
final class TurnstileUnavailable extends ServiceUnavailableHttpException
{
    public const CODE = 'TURNSTILE_UNAVAILABLE';

    public static function make(): self
    {
        return new self(30, __('turnstile.unavailable'));
    }
}
