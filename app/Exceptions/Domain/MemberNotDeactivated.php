<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use InvalidArgumentException;

/** La persona no tiene una membresía desactivada en la organización, así que no hay nada que reactivar. */
final class MemberNotDeactivated extends InvalidArgumentException
{
    public const CODE = 'MEMBER_NOT_DEACTIVATED';

    public function __construct()
    {
        parent::__construct('La persona no es un integrante desactivado de la organización (código '.self::CODE.').');
    }
}
