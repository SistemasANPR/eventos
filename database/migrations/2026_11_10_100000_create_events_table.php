<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `events` en su forma final (plan 002 DA-6): el plan 003 solo añade Actions,
 * Policies y pantallas sobre esta tabla. Una migración aplicada no se edita.
 *
 * Fechas en UTC; la zona horaria del evento (IANA) es solo para mostrarlas.
 * `name` y `description` son traducibles (un registro, un JSON por idioma).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->jsonb('name');
            $table->jsonb('description')->nullable();
            $table->string('slug');
            $table->string('type');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('timezone');
            $table->string('venue_name')->nullable();
            $table->string('venue_address')->nullable();
            $table->string('city')->nullable();
            $table->jsonb('locales')->default('["es"]');
            $table->char('default_locale', 2)->default('es');
            $table->char('currency', 3)->default('MXN');
            $table->string('status')->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'slug']);
            // Permite claves foráneas compuestas (event_id, organization_id) desde las tablas hijas.
            $table->unique(['id', 'organization_id']);
        });

        DB::statement("ALTER TABLE events ADD CONSTRAINT events_status_check CHECK (status IN ('draft', 'published', 'archived'))");
        DB::statement("ALTER TABLE events ADD CONSTRAINT events_type_check CHECK (type IN ('congress', 'forum', 'workshop', 'webinar', 'expo'))");
        DB::statement("ALTER TABLE events ADD CONSTRAINT events_currency_check CHECK (currency IN ('MXN', 'USD'))");
        DB::statement('ALTER TABLE events ADD CONSTRAINT events_dates_check CHECK (ends_at >= starts_at)');
        DB::statement('ALTER TABLE events ADD CONSTRAINT events_locales_check CHECK (locales <@ \'["es", "en", "pt"]\'::jsonb AND jsonb_exists(locales, default_locale))');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER events_organization_id_immutable
            BEFORE UPDATE OF organization_id ON events
            FOR EACH ROW EXECUTE FUNCTION prevent_organization_id_change()
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
