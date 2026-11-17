<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Actividades del programa de un evento y su vínculo con las categorías de actividades (plan 021,
 * DA-4, DA-7, DA-12; `docs/database-design.md` §8.1). Fecha y hora son LOCALES del evento, sin zona
 * (excepción a «fechas en UTC», DA-12). La tabla `activity_speaker` llega con la fase de ponentes.
 *
 * `activity_categories` gana aquí `unique(id, event_id, organization_id)` para que el pivote pueda
 * garantizar, con claves foráneas compuestas, que actividad y categoría son del mismo evento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_activities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('event_id');
            // Una actividad por propuesta; nula en las creadas a mano.
            $table->uuid('submission_id')->nullable()->unique();
            $table->string('title', 200);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->string('icon_path')->nullable();
            $table->string('image_path')->nullable();
            $table->string('summary', 200)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_published')->default(true);
            $table->timestampTz('hidden_by_reversal_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Permite las claves foráneas compuestas desde los pivotes.
            $table->unique(['id', 'organization_id']);
            $table->unique(['id', 'event_id', 'organization_id']);

            $table->foreign(['event_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('events')->restrictOnDelete();
            $table->foreign(['submission_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('submissions')->restrictOnDelete();

            $table->index(['organization_id', 'event_id', 'starts_on', 'starts_at']);
        });

        // Las cuatro marcas de tiempo van juntas o ninguna (DA-4): «sin programar».
        DB::statement(<<<'SQL'
            ALTER TABLE event_activities ADD CONSTRAINT event_activities_schedule_all_or_none_check CHECK (
                (starts_on IS NULL AND ends_on IS NULL AND starts_at IS NULL AND ends_at IS NULL)
                OR (starts_on IS NOT NULL AND ends_on IS NOT NULL AND starts_at IS NOT NULL AND ends_at IS NOT NULL)
            )
            SQL);
        // El fin no es anterior al inicio; con alguna marca nula la comparación no es falsa y pasa (ya la cubre el CHECK anterior).
        DB::statement('ALTER TABLE event_activities ADD CONSTRAINT event_activities_schedule_order_check CHECK ((ends_on, ends_at) >= (starts_on, starts_at))');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER event_activities_organization_id_immutable
            BEFORE UPDATE OF organization_id ON event_activities
            FOR EACH ROW EXECUTE FUNCTION prevent_organization_id_change()
            SQL);

        Schema::table('activity_categories', function (Blueprint $table): void {
            $table->unique(['id', 'event_id', 'organization_id']);
        });

        Schema::create('activity_activity_category', function (Blueprint $table): void {
            $table->uuid('activity_id');
            $table->uuid('activity_category_id');
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('event_id');

            $table->primary(['activity_id', 'activity_category_id']);

            // Ambos lados del mismo evento y organización; al borrar uno, el vínculo se limpia.
            $table->foreign(['activity_id', 'event_id', 'organization_id'])
                ->references(['id', 'event_id', 'organization_id'])->on('event_activities')->cascadeOnDelete();
            $table->foreign(['activity_category_id', 'event_id', 'organization_id'])
                ->references(['id', 'event_id', 'organization_id'])->on('activity_categories')->cascadeOnDelete();

            $table->index('activity_category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_activity_category');

        Schema::table('activity_categories', function (Blueprint $table): void {
            $table->dropUnique(['id', 'event_id', 'organization_id']);
        });

        Schema::dropIfExists('event_activities');
    }
};
