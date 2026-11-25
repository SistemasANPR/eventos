<?php

declare(strict_types=1);

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;

/**
 * RLS para las tablas del plan 014 F4 y F5 (`anonymization_log` y `retention_policies`), creadas después de la
 * migración general de RLS (plan 014, H-014-2): esa solo cubre las tablas que existen cuando se aplica.
 * Idempotente; no tiene efecto con un rol que ignora RLS (docs/operacion.md §10.6).
 */
return new class extends Migration
{
    private const TABLES = ['anonymization_log', 'retention_policies'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            RowLevelSecurity::enable($table);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            RowLevelSecurity::disable($table);
        }
    }
};
