<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `anonymized_at` en las tablas donde vive el dato personal de una persona (plan 014, F4; propuesta
 * de `docs/database-design.md` §8.3). Marca la fila ya anonimizada: hace idempotentes la
 * anonimización, la reaplicación tras restaurar un respaldo y la retención. Aditiva y reversible.
 */
return new class extends Migration
{
    private const TABLES = ['contacts', 'submission_participants', 'speakers', 'submission_files', 'users'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                // Instante UTC; nulo mientras la fila conserva sus datos personales.
                $blueprint->timestamp('anonymized_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('anonymized_at');
            });
        }
    }
};
