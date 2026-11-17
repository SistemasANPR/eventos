<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convocatoria de propuestas de un evento (plan 004, H-004-1). Las fechas van
 * en UTC; la zona horaria del evento solo decide cómo se muestran. `title` y
 * `description` son traducibles (un JSON por idioma).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_for_submissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('event_id');
            $table->string('slug');
            $table->string('submission_type');
            $table->jsonb('title');
            $table->jsonb('description')->nullable();
            $table->jsonb('locales');
            $table->timestamp('opens_at');
            $table->timestamp('closes_at');
            $table->timestamp('results_at')->nullable();
            $table->string('status')->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'event_id', 'slug']);
            // Permite claves foráneas compuestas (call_id, organization_id) desde formularios y propuestas.
            $table->unique(['id', 'organization_id']);
            // Convocatoria y evento comparten organización: la base lo garantiza, además de la aplicación.
            $table->foreign(['event_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('events')->restrictOnDelete();
            $table->index(['organization_id', 'event_id', 'status']);
        });

        DB::statement("ALTER TABLE call_for_submissions ADD CONSTRAINT call_for_submissions_status_check CHECK (status IN ('draft', 'published', 'paused', 'closed'))");
        DB::statement("ALTER TABLE call_for_submissions ADD CONSTRAINT call_for_submissions_type_check CHECK (submission_type IN ('session', 'panel', 'poster', 'workshop'))");
        DB::statement('ALTER TABLE call_for_submissions ADD CONSTRAINT call_for_submissions_closes_after_opens_check CHECK (closes_at > opens_at)');
        DB::statement('ALTER TABLE call_for_submissions ADD CONSTRAINT call_for_submissions_results_check CHECK (results_at IS NULL OR results_at >= closes_at)');
        DB::statement('ALTER TABLE call_for_submissions ADD CONSTRAINT call_for_submissions_locales_check CHECK (locales <@ \'["es", "en", "pt"]\'::jsonb AND jsonb_array_length(locales) >= 1)');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER call_for_submissions_organization_id_immutable
            BEFORE UPDATE OF organization_id ON call_for_submissions
            FOR EACH ROW EXECUTE FUNCTION prevent_organization_id_change()
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('call_for_submissions');
    }
};
