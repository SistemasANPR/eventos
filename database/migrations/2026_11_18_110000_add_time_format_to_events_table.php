<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Formato de hora del evento (plan 022, F1): '12h' o '24h'. Solo cambia cómo se muestran las horas,
 * no lo guardado. Los eventos que ya existen toman el valor por defecto '12h' (plan 022, DA-4):
 * sus pantallas pasan a 12 h al desplegar, y cada organizador puede volver a 24 h en el evento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->string('time_format', 3)->default('12h');
        });

        DB::statement("ALTER TABLE events ADD CONSTRAINT events_time_format_check CHECK (time_format IN ('12h', '24h'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS events_time_format_check');

        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn('time_format');
        });
    }
};
