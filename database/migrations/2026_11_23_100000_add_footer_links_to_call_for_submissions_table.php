<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enlaces del pie de la página pública de la convocatoria: se puede desactivar «Volver al evento»
 * (por defecto sigue visible) y agregar un enlace propio con texto y dirección. No hay datos que migrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_for_submissions', function (Blueprint $table): void {
            $table->boolean('show_back_link')->default(true);
            $table->string('extra_link_label', 100)->nullable();
            $table->string('extra_link_url', 2048)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('call_for_submissions', function (Blueprint $table): void {
            $table->dropColumn(['show_back_link', 'extra_link_label', 'extra_link_url']);
        });
    }
};
