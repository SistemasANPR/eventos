<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Salas de un evento (plan 031, DA-2; `docs/database-design.md` §8.4): catálogo propio de cada evento,
 * como las categorías del plan 021. `unique(id, event_id, organization_id)` es la clave a la que apunta
 * `event_activities.room_id` para que una actividad solo use salas de su propio evento y organización.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('event_id');
            $table->string('name', 100);
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->unique(['id', 'organization_id']);
            $table->unique(['id', 'event_id', 'organization_id']);
            $table->foreign(['event_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('events')->restrictOnDelete();
            $table->index(['organization_id', 'event_id', 'position']);
        });

        // Un nombre por evento, sin distinguir mayúsculas (CA-031-5).
        DB::statement('CREATE UNIQUE INDEX rooms_event_name_unique ON rooms (event_id, lower(name))');
        DB::statement('ALTER TABLE rooms ADD CONSTRAINT rooms_position_check CHECK (position >= 1)');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER rooms_organization_id_immutable
            BEFORE UPDATE OF organization_id ON rooms
            FOR EACH ROW EXECUTE FUNCTION prevent_organization_id_change()
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
