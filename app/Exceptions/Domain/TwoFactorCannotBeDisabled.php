<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use RuntimeException;

/** Alguien intentó desactivar su propia verificación en dos pasos (plan 014, CA-014-4). */
final class TwoFactorCannotBeDisabled extends RuntimeException
{
    public const CODE = 'TWO_FACTOR_CANNOT_BE_DISABLED';

    public function __construct()
    {
        parent::__construct('La verificación en dos pasos no se puede desactivar por uno mismo; la reinicia otro administrador (código '.self::CODE.').');
    }
}
