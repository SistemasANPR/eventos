<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sala de una actividad (plan 031, DA-3; `docs/database-design.md` §8.4). Nula: una actividad puede no
 * tener sala. La clave foránea compuesta obliga a que la sala sea del mismo evento y organización, y
 * es restrictiva: una sala en uso no se puede borrar. Una actividad sin programar no puede tener sala.
 * Las actividades existentes quedan sin sala, así que el `CHECK` se cumple desde el primer momento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_activities', function (Blueprint $table): void {
            $table->uuid('room_id')->nullable()->after('submission_id');

            $table->foreign(['room_id', 'event_id', 'organization_id'])
                ->references(['id', 'event_id', 'organization_id'])->on('rooms')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE event_activities ADD CONSTRAINT event_activities_room_requires_schedule_check CHECK (room_id IS NULL OR starts_on IS NOT NULL)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE event_activities DROP CONSTRAINT event_activities_room_requires_schedule_check');

        Schema::table('event_activities', function (Blueprint $table): void {
            $table->dropForeign(['room_id', 'event_id', 'organization_id']);
            $table->dropColumn('room_id');
        });
    }
};
