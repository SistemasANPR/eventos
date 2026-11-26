<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Datos adicionales» de un hospedaje y de un patrocinador (plan 030, DA-7): hasta 10 datos por registro, cada uno con
 * título libre y tipo texto o foto. Viven en una columna JSON de la propia fila (lista de `{id, label, type, value|path}`);
 * la ruta de una foto es del disco `media`, nunca una URL. Sin datos adicionales la columna queda nula.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['lodgings', 'sponsors'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->json('extra_fields')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['lodgings', 'sponsors'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('extra_fields');
            });
        }
    }
};
