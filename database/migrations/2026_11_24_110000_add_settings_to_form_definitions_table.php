<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajustes del formulario que no son preguntas: qué preguntas de los participantes se muestran y cuáles son
 * obligatorias, dónde va el bloque de participantes y los títulos de la página y del apartado de preguntas.
 * Nulo = todo como siempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_definitions', function (Blueprint $table): void {
            $table->json('settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('form_definitions', function (Blueprint $table): void {
            $table->dropColumn('settings');
        });
    }
};
