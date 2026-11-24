<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Límite de personas (participantes) por propuesta, fijado en el formulario. Nulo = el formulario no lo define
 * y manda el de la convocatoria (por defecto 5). Con valor, se aplica a la convocatoria que usa el formulario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_definitions', function (Blueprint $table): void {
            $table->unsignedSmallInteger('max_participants')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('form_definitions', function (Blueprint $table): void {
            $table->dropColumn('max_participants');
        });
    }
};
