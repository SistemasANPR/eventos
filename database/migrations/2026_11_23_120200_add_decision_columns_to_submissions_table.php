<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Columnas de la decisión sobre una propuesta (plan 012, §4 propuesta de datos): el comentario para el
 * autor (texto plano, hasta 1000 caracteres) y el instante UTC en que se publicó su resultado. Ambas
 * son nulas y no transforman datos existentes; el estado sigue siendo el de `submissions_status_check`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table): void {
            $table->text('author_comment')->nullable();
            $table->timestamp('results_published_at')->nullable();
        });

        DB::statement('ALTER TABLE submissions ADD CONSTRAINT submissions_author_comment_check CHECK (char_length(author_comment) <= 1000)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE submissions DROP CONSTRAINT IF EXISTS submissions_author_comment_check');

        Schema::table('submissions', function (Blueprint $table): void {
            $table->dropColumn(['author_comment', 'results_published_at']);
        });
    }
};
