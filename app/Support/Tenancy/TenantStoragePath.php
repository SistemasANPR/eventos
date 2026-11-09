<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Support\Tenancy\Exceptions\MissingOrganizationContext;
use InvalidArgumentException;

/**
 * Ruta de almacenamiento bajo `organizations/{id}/` (app/CLAUDE.md §8).
 * Rechaza rutas absolutas y los segmentos `..`, que saldrían del directorio de
 * la organización.
 */
final class TenantStoragePath
{
    /**
     * @throws MissingOrganizationContext
     * @throws InvalidArgumentException
     */
    public static function for(string $path): string
    {
        $organizationId = CurrentOrganization::id();

        if (str_starts_with($path, '/') || in_array('..', explode('/', $path), true)) {
            throw new InvalidArgumentException('La ruta debe ser relativa y no puede contener "..".');
        }

        return "organizations/{$organizationId}/{$path}";
    }
}
