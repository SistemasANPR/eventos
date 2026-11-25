<?php

declare(strict_types=1);

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;

/*
 * RLS de PostgreSQL como segunda barrera de aislamiento (plan 014, H-014-2, DA-2).
 *
 * Habilita RLS y crea las políticas `tenant_isolation` y `platform_read` en toda tabla con
 * `organization_id` que no esté en la lista cerrada `RowLevelSecurity::PLATFORM_TABLES`.
 * No cambia datos ni esquema de columnas, y no tiene efecto mientras la aplicación se conecte
 * con un rol que ignora RLS (propietario o superusuario): la activación es cambiar ese rol
 * (docs/operacion.md §10). Sin FORCE: el rol de migraciones (propietario) no depende de RLS.
 *
 * Reversa (`migrate:rollback`): quita las políticas, deshabilita RLS en cada tabla y borra las
 * dos funciones de apoyo; no toca ningún dato. Las tablas que otras ramas protejan con
 * `RowLevelSecurity::enable()` en su propia migración se revierten antes que esta.
 */
return new class extends Migration
{
    public function up(): void
    {
        RowLevelSecurity::enableMissing();
    }

    public function down(): void
    {
        RowLevelSecurity::disableAll();
    }
};
