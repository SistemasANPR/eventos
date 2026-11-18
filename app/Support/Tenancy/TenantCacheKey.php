<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Support\Tenancy\Exceptions\MissingOrganizationContext;

/**
 * Clave de caché con la organización en contexto, para que una organización
 * nunca lea la caché de otra (ADR-0003).
 */
final class TenantCacheKey
{
    /** @throws MissingOrganizationContext */
    public static function for(string $key): string
    {
        return 'organization:'.CurrentOrganization::id().':'.$key;
    }
}
