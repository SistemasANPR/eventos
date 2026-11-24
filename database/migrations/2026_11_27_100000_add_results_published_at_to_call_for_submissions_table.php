<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo se publicaron los resultados de una convocatoria (plan 012, F2, DA-2). Es nulo hasta que un
 * usuario con `submissions.decide` los publica; desde entonces, las decisiones nuevas se avisan al autor
 * al decidir. Columna nula: no transforma datos existentes. Instante UTC. Quién publicó queda en la
 * auditoría (`call_results_published`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_for_submissions', function (Blueprint $table): void {
            $table->timestamp('results_published_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('call_for_submissions', function (Blueprint $table): void {
            $table->dropColumn('results_published_at');
        });
    }
};
