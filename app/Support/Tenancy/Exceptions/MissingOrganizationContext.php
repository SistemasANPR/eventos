<?php

declare(strict_types=1);

namespace App\Support\Tenancy\Exceptions;

use RuntimeException;

/**
 * Se consultó o creó un dato de una organización sin contexto de organización.
 * Falla cerrada: nunca se devuelve una lista vacía ni todos los registros.
 */
final class MissingOrganizationContext extends RuntimeException
{
    public const CODE = 'MISSING_ORGANIZATION_CONTEXT';

    public function __construct()
    {
        parent::__construct(
            'No hay organización en contexto. Establecerla con CurrentOrganization::set() o por la ruta (código '.self::CODE.').',
        );
    }
}
