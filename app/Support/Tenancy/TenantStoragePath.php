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

        if (str_contains($path, '\\') || str_contains($path, "\0") || str_starts_with($path, '/')) {
            throw new InvalidArgumentException('La ruta debe ser relativa, sin "\\" ni bytes nulos.');
        }

        foreach (explode('/', $path) as $segment) {
            if (in_array($segment, ['', '.', '..'], true)) {
                throw new InvalidArgumentException('La ruta no puede tener segmentos vacíos, "." ni "..".');
            }
        }

        return "organizations/{$organizationId}/{$path}";
    }
}
