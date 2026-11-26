<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cupo y datos de un lugar externo en la actividad (plan 035, DA-3 y DA-4; `docs/database-design.md` §8.5).
 * `quota` nulo significa «sin cupo»; `transport_info` no nulo significa «incluye transporte». Que el punto
 * de reunión y el transporte solo existan con un lugar externo lo comprueban las Actions, con el evento
 * bloqueado. Las actividades existentes quedan sin ninguno de los tres y siguen siendo válidas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_activities', function (Blueprint $table): void {
            $table->unsignedInteger('quota')->nullable();
            $table->string('meeting_point', 500)->nullable();
            $table->string('transport_info', 500)->nullable();
        });

        DB::statement('ALTER TABLE event_activities ADD CONSTRAINT event_activities_quota_check CHECK (quota IS NULL OR quota > 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE event_activities DROP CONSTRAINT IF EXISTS event_activities_quota_check');

        Schema::table('event_activities', function (Blueprint $table): void {
            $table->dropColumn(['quota', 'meeting_point', 'transport_info']);
        });
    }
};
