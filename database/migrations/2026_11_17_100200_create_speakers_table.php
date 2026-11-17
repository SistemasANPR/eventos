<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ponentes de un evento y su relación con las categorías de ponentes (plan 021, DA-1, DA-5, DA-7,
 * DA-17; `docs/database-design.md` §8.1). Cada ponente es una ficha de UN evento: editarla no cambia
 * la de otro. El vínculo con la persona de la organización (`contact_id`) y con la propuesta de la que
 * nació (`created_by_submission_id`) son opcionales y van por clave foránea compuesta con la
 * organización, igual que `submissions`.
 *
 * El correo es único por evento sin distinguir mayúsculas, solo entre fichas no borradas: una ficha
 * borrada libera su correo. El pivote con categorías lleva el evento y exige por clave foránea
 * compuesta que ponente y categoría sean del MISMO evento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('speakers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('event_id');
            $table->uuid('contact_id')->nullable();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email', 254)->nullable();
            // ISO 3166-1 alfa-2.
            $table->char('country', 2)->nullable();
            $table->string('company', 160)->nullable();
            $table->string('job_title', 160)->nullable();
            // Descripción de una línea.
            $table->string('tagline', 160)->nullable();
            // HTML saneado con `RichTextSanitizer`.
            $table->text('bio')->nullable();
            $table->string('photo_path', 255)->nullable();
            $table->jsonb('social_links')->default('[]');
            $table->boolean('is_published')->default(true);
            $table->timestampTz('hidden_by_reversal_at')->nullable();
            $table->uuid('created_by_submission_id')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['id', 'organization_id']);
            // Lo necesita el pivote con categorías para exigir «mismo evento».
            $table->unique(['id', 'event_id']);
            $table->foreign(['event_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('events')->restrictOnDelete();
            // Con `contact_id` nulo la clave compuesta no se evalúa (MATCH SIMPLE): el vínculo es opcional.
            $table->foreign(['contact_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('contacts')->restrictOnDelete();
            $table->foreign(['created_by_submission_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('submissions')->restrictOnDelete();
            $table->index(['organization_id', 'event_id', 'last_name', 'first_name']);
        });

        // Un correo por evento, sin distinguir mayúsculas, entre las fichas no borradas (CA-021-1, DA-5).
        DB::statement('CREATE UNIQUE INDEX speakers_event_email_unique ON speakers (event_id, lower(email)) WHERE email IS NOT NULL AND deleted_at IS NULL');
        DB::statement("ALTER TABLE speakers ADD CONSTRAINT speakers_names_not_blank_check CHECK (btrim(first_name) <> '' AND btrim(last_name) <> '')");
        DB::statement("ALTER TABLE speakers ADD CONSTRAINT speakers_social_links_array_check CHECK (jsonb_typeof(social_links) = 'array')");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER speakers_organization_id_immutable
            BEFORE UPDATE OF organization_id ON speakers
            FOR EACH ROW EXECUTE FUNCTION prevent_organization_id_change()
            SQL);

        // El pivote necesita apuntar a la categoría por (id, evento).
        Schema::table('speaker_categories', function (Blueprint $table): void {
            $table->unique(['id', 'event_id']);
        });

        Schema::create('speaker_speaker_category', function (Blueprint $table): void {
            $table->uuid('speaker_id');
            $table->uuid('speaker_category_id');
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('event_id');

            $table->primary(['speaker_id', 'speaker_category_id']);
            $table->foreign(['event_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('events')->restrictOnDelete();
            // Ponente y categoría del mismo evento; borrar cualquiera de los dos limpia el vínculo.
            $table->foreign(['speaker_id', 'event_id'])
                ->references(['id', 'event_id'])->on('speakers')->cascadeOnDelete();
            $table->foreign(['speaker_category_id', 'event_id'])
                ->references(['id', 'event_id'])->on('speaker_categories')->cascadeOnDelete();
            $table->index('speaker_category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('speaker_speaker_category');

        Schema::table('speaker_categories', function (Blueprint $table): void {
            $table->dropUnique(['id', 'event_id']);
        });

        Schema::dropIfExists('speakers');
    }
};
