<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Evaluaciones, respuestas e historial de reevaluaciones (plan 011, F4; propuesta de datos §4.2 y §4.3
 * aprobada el 2026-11-23). Una evaluación es única por asignación y guarda la versión de la rúbrica con
 * que se hizo. `revision` cuenta los envíos: 0 es un borrador que nunca se envió, 1 el primer envío y cada
 * reevaluación suma uno; la versión anterior se conserva en `review_history`. `reviews` usa borrado lógico:
 * una evaluación es evidencia.
 *
 * Las claves compuestas con la organización garantizan en la base que asignación, rúbrica, criterio y
 * evaluación comparten la organización de la fila. Una respuesta es un entero (criterio de calificación,
 * dentro de la escala, que valida la Action) o un texto, nunca las dos cosas ni ninguna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('review_assignment_id');
            $table->uuid('rubric_id');
            // Instante UTC del último envío; nulo mientras es un borrador.
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['id', 'organization_id']);
            $table->unique('review_assignment_id');
            $table->foreign(['review_assignment_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('review_assignments')->restrictOnDelete();
            $table->foreign(['rubric_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('rubrics')->restrictOnDelete();
            $table->index(['organization_id', 'rubric_id']);
        });

        Schema::create('review_answers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('review_id');
            $table->uuid('rubric_criterion_id');
            $table->smallInteger('value_int')->nullable();
            $table->text('value_text')->nullable();
            $table->timestamps();

            $table->unique(['review_id', 'rubric_criterion_id']);
            $table->foreign(['review_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('reviews')->cascadeOnDelete();
            $table->foreign(['rubric_criterion_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('rubric_criteria')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE review_answers ADD CONSTRAINT review_answers_value_check CHECK ((value_int IS NOT NULL) <> (value_text IS NOT NULL))');

        Schema::create('review_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('review_id');
            $table->uuid('rubric_id');
            $table->unsignedInteger('revision');
            $table->timestamp('submitted_at');
            // Respuestas de esa versión: [{rubric_criterion_id, value_int, value_text}].
            $table->jsonb('answers');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['review_id', 'revision']);
            $table->foreign(['review_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('reviews')->restrictOnDelete();
            $table->foreign(['rubric_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('rubrics')->restrictOnDelete();
        });

        foreach (['reviews', 'review_answers', 'review_history'] as $name) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$name}_organization_id_immutable
                BEFORE UPDATE OF organization_id ON {$name}
                FOR EACH ROW EXECUTE FUNCTION prevent_organization_id_change()
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('review_history');
        Schema::dropIfExists('review_answers');
        Schema::dropIfExists('reviews');
    }
};
