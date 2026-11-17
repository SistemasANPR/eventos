<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rúbricas de evaluación y sus criterios (plan 011, H-011-1; propuesta de datos
 * §4.2b aprobada el 2026-11-13). Una rúbrica es de un evento y un tipo de
 * propuesta y se versiona (`unique(event_id, submission_type_id, version)`):
 * las evaluaciones del plan 011 F4 guardarán la versión con que se hicieron.
 * Las claves compuestas garantizan en la base que evento, tipo y criterios
 * comparten la organización de la rúbrica.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rubrics', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('event_id');
            $table->uuid('submission_type_id');
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('draft');
            $table->boolean('blind_review')->default(false);
            $table->unsignedSmallInteger('min_reviewers')->default(2);
            // En unidades de la escala (las calificaciones son enteras, la diferencia también).
            $table->unsignedSmallInteger('discrepancy_threshold')->default(1);
            $table->timestamps();

            // Permite la clave foránea compuesta (rubric_id, organization_id) desde criterios y evaluaciones.
            $table->unique(['id', 'organization_id']);
            $table->unique(['event_id', 'submission_type_id', 'version']);
            $table->foreign(['event_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('events')->restrictOnDelete();
            $table->foreign(['submission_type_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('submission_types')->restrictOnDelete();
            $table->index(['organization_id', 'event_id']);
        });

        DB::statement("ALTER TABLE rubrics ADD CONSTRAINT rubrics_status_check CHECK (status IN ('draft', 'published'))");
        DB::statement('ALTER TABLE rubrics ADD CONSTRAINT rubrics_min_reviewers_check CHECK (min_reviewers >= 1)');
        DB::statement('ALTER TABLE rubrics ADD CONSTRAINT rubrics_discrepancy_threshold_check CHECK (discrepancy_threshold > 0)');

        Schema::create('rubric_criteria', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('rubric_id');
            $table->unsignedInteger('position');
            $table->jsonb('label');
            $table->string('kind', 10)->default('scale');
            $table->smallInteger('scale_min')->default(1);
            $table->smallInteger('scale_max')->default(5);
            $table->decimal('weight', 6, 2)->default(1);
            $table->timestamps();

            // Permite la clave foránea compuesta (criterion_id, organization_id) desde las respuestas (F4).
            $table->unique(['id', 'organization_id']);
            $table->unique(['rubric_id', 'position']);
            $table->foreign(['rubric_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('rubrics')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE rubric_criteria ADD CONSTRAINT rubric_criteria_kind_check CHECK (kind IN ('scale', 'text'))");
        DB::statement('ALTER TABLE rubric_criteria ADD CONSTRAINT rubric_criteria_scale_check CHECK (scale_min < scale_max)');
        DB::statement('ALTER TABLE rubric_criteria ADD CONSTRAINT rubric_criteria_weight_check CHECK (weight > 0)');

        foreach (['rubrics', 'rubric_criteria'] as $name) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$name}_organization_id_immutable
                BEFORE UPDATE OF organization_id ON {$name}
                FOR EACH ROW EXECUTE FUNCTION prevent_organization_id_change()
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rubric_criteria');
        Schema::dropIfExists('rubrics');
    }
};
