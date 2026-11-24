<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

/**
 * Marca de proceso: la petición en curso tiene acceso de **lectura** de plataforma a las
 * tablas con organización (plan 014, DA-2). La base de datos la lee con la variable de
 * sesión `app.platform_access`, que `DatabaseTenantContext` sincroniza antes de cada consulta.
 *
 * Solo `OrganizationScopeBypass::apply()` la enciende, tras comprobar que hay un usuario de
 * plataforma autenticado y dejar su registro de auditoría. La política de plataforma de
 * `RowLevelSecurity` es solo de lectura (`FOR SELECT`): no concede escribir filas ajenas.
 * Quien enciende la marca es responsable de apagarla: la petición lo hace al terminar y
 * el trabajador de colas, entre un job y otro.
 */
final class PlatformAccess
{
    private static bool $enabled = false;

    public static function enable(): void
    {
        self::$enabled = true;
    }

    public static function disable(): void
    {
        self::$enabled = false;
    }

    public static function isEnabled(): bool
    {
        return self::$enabled;
    }
}
