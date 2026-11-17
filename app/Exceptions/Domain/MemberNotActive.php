<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use InvalidArgumentException;

/** La persona no tiene una membresía activa en la organización. */
final class MemberNotActive extends InvalidArgumentException
{
    public const CODE = 'MEMBER_NOT_ACTIVE';

    public function __construct()
    {
        parent::__construct('La persona no es miembro activo de la organización (código '.self::CODE.').');
    }
}
