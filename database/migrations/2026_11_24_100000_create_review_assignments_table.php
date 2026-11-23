<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Asignaciones de evaluadores a propuestas (plan 011, F3a; propuesta de datos §4.2 aprobada el
 * 2026-11-23, con el estado `cancelled`, `assigned_by`, `assigned_at` y `source`). Es único por
 * `(propuesta, evaluador)`.
 *
 * Las claves compuestas garantizan en la base que la propuesta, su evento y el evaluador comparten la
 * organización de la asignación. Que el evaluador tenga además un `event_assignments` de rol `reviewer`
 * en ese evento lo exige la Action `AssignReviewers` (CA-011-10). `assigned_by` es nulo solo cuando
 * asigna el sistema (una regla de alcance, F3b).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('event_id');
            $table->uuid('submission_id');
            $table->uuid('reviewer_id');
            $table->string('status', 20)->default('pending');
            $table->foreignUuid('assigned_by')->nullable()->constrained('users')->restrictOnDelete();
            // Instante UTC.
            $table->timestamp('assigned_at');
            $table->string('source', 10)->default('manual');
            $table->timestamps();

            // Permite claves foráneas compuestas (assignment_id, organization_id) desde las evaluaciones (F4).
            $table->unique(['id', 'organization_id']);
            $table->unique(['submission_id', 'reviewer_id']);
            $table->foreign(['submission_id', 'event_id', 'organization_id'])
                ->references(['id', 'event_id', 'organization_id'])->on('submissions')->restrictOnDelete();
            $table->foreign(['organization_id', 'reviewer_id'])
                ->references(['organization_id', 'user_id'])->on('organization_user')->restrictOnDelete();
            $table->index(['organization_id', 'event_id', 'reviewer_id']);
            $table->index(['organization_id', 'submission_id']);
        });

        DB::statement("ALTER TABLE review_assignments ADD CONSTRAINT review_assignments_status_check CHECK (status IN ('pending', 'in_progress', 'submitted', 'declined_conflict', 'cancelled'))");
        DB::statement("ALTER TABLE review_assignments ADD CONSTRAINT review_assignments_source_check CHECK (source IN ('manual', 'scope'))");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER review_assignments_organization_id_immutable
            BEFORE UPDATE OF organization_id ON review_assignments
            FOR EACH ROW EXECUTE FUNCTION prevent_organization_id_change()
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('review_assignments');
    }
};
