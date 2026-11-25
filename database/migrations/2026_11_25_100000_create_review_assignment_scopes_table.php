<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reglas permanentes de alcance de la asignación (plan 011, F3b; propuesta de datos §4.2 aprobada el
 * 2026-11-23): un evaluador asignado a un eje o a una convocatoria recibe solo, sin correo, cada propuesta que
 * llegue después dentro del alcance (DA-6 y DA-8). Quitar la regla no borra las asignaciones ya hechas.
 *
 * El alcance se guarda en dos columnas (`track_id` o `call_id`, exactamente una según `scope_type`) en vez de un
 * identificador genérico: así cada una lleva su clave foránea compuesta `(alcance, evento, organización)` y la
 * base garantiza que el eje o la convocatoria son del mismo evento y la misma organización. Para eso se añade
 * `unique(id, event_id, organization_id)` a `tracks` y `call_for_submissions` (aditivo, no cambia datos).
 * Único por evaluador y alcance; que el evaluador tenga `event_assignments` de rol `reviewer` lo vigila la Action.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $table): void {
            $table->unique(['id', 'event_id', 'organization_id']);
        });

        Schema::table('call_for_submissions', function (Blueprint $table): void {
            $table->unique(['id', 'event_id', 'organization_id']);
        });

        Schema::create('review_assignment_scopes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('event_id');
            $table->uuid('reviewer_id');
            $table->string('scope_type', 10);
            $table->uuid('track_id')->nullable();
            $table->uuid('call_id')->nullable();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['id', 'organization_id']);
            // Las columnas nulas no chocan entre sí: cada una garantiza la unicidad de su tipo de alcance.
            $table->unique(['reviewer_id', 'track_id']);
            $table->unique(['reviewer_id', 'call_id']);
            $table->foreign(['event_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('events')->restrictOnDelete();
            $table->foreign(['organization_id', 'reviewer_id'])
                ->references(['organization_id', 'user_id'])->on('organization_user')->restrictOnDelete();
            $table->foreign(['track_id', 'event_id', 'organization_id'])
                ->references(['id', 'event_id', 'organization_id'])->on('tracks')->restrictOnDelete();
            $table->foreign(['call_id', 'event_id', 'organization_id'])
                ->references(['id', 'event_id', 'organization_id'])->on('call_for_submissions')->restrictOnDelete();
            $table->index(['organization_id', 'event_id']);
            $table->index('track_id');
            $table->index('call_id');
        });

        DB::statement("ALTER TABLE review_assignment_scopes ADD CONSTRAINT review_assignment_scopes_scope_check CHECK (
            (scope_type = 'track' AND track_id IS NOT NULL AND call_id IS NULL)
            OR (scope_type = 'call' AND call_id IS NOT NULL AND track_id IS NULL)
        )");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER review_assignment_scopes_organization_id_immutable
            BEFORE UPDATE OF organization_id ON review_assignment_scopes
            FOR EACH ROW EXECUTE FUNCTION prevent_organization_id_change()
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('review_assignment_scopes');

        Schema::table('call_for_submissions', function (Blueprint $table): void {
            $table->dropUnique(['id', 'event_id', 'organization_id']);
        });

        Schema::table('tracks', function (Blueprint $table): void {
            $table->dropUnique(['id', 'event_id', 'organization_id']);
        });
    }
};
