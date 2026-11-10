<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use InvalidArgumentException;

/** La operación dejaría a la organización sin ningún administrador activo (CA-003-25). */
final class LastOrganizationAdmin extends InvalidArgumentException
{
    public const CODE = 'LAST_ORGANIZATION_ADMIN';

    public function __construct()
    {
        parent::__construct('La organización debe conservar al menos un administrador activo (código '.self::CODE.').');
    }
}
