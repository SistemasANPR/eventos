<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * El envío no trae la verificación anti-robots, o trae una que el servicio rechaza
 * o que ya se usó (plan 006, CA-006-21 y CA-006-24). Responde 422 sin explicar cuál de los casos fue.
 */
final class TurnstileInvalid extends UnprocessableEntityHttpException
{
    public const CODE = 'TURNSTILE_INVALID';

    public static function make(): self
    {
        return new self(__('turnstile.invalid'));
    }
}
