<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Organización propia en la bitácora (plan 016, DT-01): permite filtrar y mostrar
 * la auditoría por organización con integridad e índice, en vez de leerla de
 * `properties`. Nula en los hechos de plataforma. Los registros existentes se
 * rellenan desde `properties->organization_id` solo si coincide con una
 * organización que existe; el resto queda nulo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->foreignUuid('organization_id')->nullable()->after('log_name')->constrained()->restrictOnDelete();
            $table->index(['organization_id', 'created_at']);
        });

        // Se compara como texto: un valor que no es un uuid simplemente no coincide, sin error de conversión.
        DB::statement(<<<'SQL'
            UPDATE activity_log
            SET organization_id = organizations.id
            FROM organizations
            WHERE organizations.id::text = activity_log.properties::jsonb ->> 'organization_id'
            SQL);
    }

    /** La información sigue en `properties`: quitar la columna no pierde nada. */
    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'created_at']);
            $table->dropConstrainedForeignId('organization_id');
        });
    }
};
