<?php

declare(strict_types=1);

namespace App\Support\Tenancy\Exceptions;

use RuntimeException;

/**
 * Se intentó crear un registro para una organización distinta de la del
 * contexto, o cambiar la organización de un registro existente.
 */
final class OrganizationMismatch extends RuntimeException
{
    public const CODE = 'ORGANIZATION_MISMATCH';

    public function __construct(string $detail)
    {
        parent::__construct($detail.' (código '.self::CODE.').');
    }
}
