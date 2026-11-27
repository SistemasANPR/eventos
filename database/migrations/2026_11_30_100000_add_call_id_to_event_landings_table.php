<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Landing propia de cada convocatoria (plan 033): `event_landings` gana `call_id`, opcional. Sin él (`NULL`) la
 * fila es la landing del evento, como hasta ahora; con él es la landing de esa convocatoria. Sigue habiendo a
 * lo sumo una landing del evento y una por convocatoria (dos índices únicos parciales), y la clave foránea
 * compuesta `(call_id, event_id, organization_id)` garantiza que la convocatoria es de ese evento y de esa
 * organización. Las landings que ya existen no cambian.
 */
return new class extends Migration
{
    public function up(): void
    {
        // La clave foránea compuesta usa la clave única `(id, event_id, organization_id)` que `call_for_submissions` ya tiene
        // (la usan también los alcances de asignación de evaluación).
        Schema::table('event_landings', function (Blueprint $table): void {
            $table->uuid('call_id')->nullable()->after('event_id');
            $table->dropUnique(['event_id']);

            $table->foreign(['call_id', 'event_id', 'organization_id'])
                ->references(['id', 'event_id', 'organization_id'])->on('call_for_submissions')->restrictOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX event_landings_event_only_unique ON event_landings (event_id) WHERE call_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX event_landings_call_unique ON event_landings (call_id) WHERE call_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS event_landings_call_unique');
        DB::statement('DROP INDEX IF EXISTS event_landings_event_only_unique');

        // Las landings de convocatoria no caben en el esquema anterior: se quitan (con sus imágenes).
        DB::table('landing_images')->whereIn('event_landing_id', DB::table('event_landings')->whereNotNull('call_id')->select('id'))->delete();
        DB::table('event_landings')->whereNotNull('call_id')->delete();

        Schema::table('event_landings', function (Blueprint $table): void {
            $table->dropForeign(['call_id', 'event_id', 'organization_id']);
            $table->dropColumn('call_id');
            $table->unique(['event_id']);
        });
    }
};
