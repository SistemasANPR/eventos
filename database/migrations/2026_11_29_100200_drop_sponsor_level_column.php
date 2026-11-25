<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Baja del texto libre `sponsors.level` (plan 030, DA-6): su valor ya vive en `sponsor_categories` gracias a la
 * migración de datos anterior. La reversa solo devuelve la columna (nula); `convert_sponsor_level_into_categories`
 * es la que copia de vuelta los nombres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sponsors', function (Blueprint $table): void {
            $table->dropColumn('level');
        });
    }

    public function down(): void
    {
        Schema::table('sponsors', function (Blueprint $table): void {
            $table->string('level', 80)->nullable();
        });
    }
};
