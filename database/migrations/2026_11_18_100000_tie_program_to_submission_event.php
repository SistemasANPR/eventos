<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La propuesta y lo que nace de ella son del MISMO evento y de la misma organización (plan 021, F5,
 * CA-021-7 y CA-021-17; `docs/database-design.md` §8.1). Hasta ahora `event_activities.submission_id` y
 * `speakers.created_by_submission_id` solo exigían la misma organización; con la Action
 * `PromoteSubmissionToProgram` el evento también debe coincidir. Se reemplazan las dos claves foráneas
 * por claves compuestas `(propuesta, evento, organización)`, para lo cual `submissions` gana
 * `unique(id, event_id, organization_id)` (`id` ya es único: no se pierde ninguna garantía).
 *
 * Con la columna nula la clave compuesta no se evalúa (MATCH SIMPLE): el vínculo sigue siendo opcional.
 * No toca datos: si alguna fila existente ya cruzara eventos, la migración fallaría al aplicarse en
 * lugar de ocultarlo (no existe código que cree esas filas antes de esta fase).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table): void {
            $table->unique(['id', 'event_id', 'organization_id']);
        });

        Schema::table('event_activities', function (Blueprint $table): void {
            $table->dropForeign(['submission_id', 'organization_id']);
            $table->foreign(['submission_id', 'event_id', 'organization_id'])
                ->references(['id', 'event_id', 'organization_id'])->on('submissions')->restrictOnDelete();
        });

        Schema::table('speakers', function (Blueprint $table): void {
            $table->dropForeign(['created_by_submission_id', 'organization_id']);
            $table->foreign(['created_by_submission_id', 'event_id', 'organization_id'])
                ->references(['id', 'event_id', 'organization_id'])->on('submissions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('speakers', function (Blueprint $table): void {
            $table->dropForeign(['created_by_submission_id', 'event_id', 'organization_id']);
            $table->foreign(['created_by_submission_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('submissions')->restrictOnDelete();
        });

        Schema::table('event_activities', function (Blueprint $table): void {
            $table->dropForeign(['submission_id', 'event_id', 'organization_id']);
            $table->foreign(['submission_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('submissions')->restrictOnDelete();
        });

        Schema::table('submissions', function (Blueprint $table): void {
            $table->dropUnique(['id', 'event_id', 'organization_id']);
        });
    }
};
