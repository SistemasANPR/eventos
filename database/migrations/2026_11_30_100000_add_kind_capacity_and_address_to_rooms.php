<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lugares del evento (plan 035, DA-1; `docs/database-design.md` §8.5): la sala gana un tipo (`room` dentro
 * del recinto, `external` fuera de él), capacidad, dirección y enlace de mapa, todo opcional. Las salas que
 * ya existen quedan como `room`, sin capacidad ni dirección, y siguen siendo válidas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table): void {
            $table->string('kind', 16)->default('room');
            $table->unsignedInteger('capacity')->nullable();
            $table->boolean('capacity_is_public')->default(false);
            $table->string('address', 255)->nullable();
            $table->string('map_url', 2048)->nullable();
        });

        DB::statement("ALTER TABLE rooms ADD CONSTRAINT rooms_kind_check CHECK (kind IN ('room', 'external'))");
        DB::statement('ALTER TABLE rooms ADD CONSTRAINT rooms_capacity_check CHECK (capacity IS NULL OR capacity > 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE rooms DROP CONSTRAINT IF EXISTS rooms_capacity_check');
        DB::statement('ALTER TABLE rooms DROP CONSTRAINT IF EXISTS rooms_kind_check');

        Schema::table('rooms', function (Blueprint $table): void {
            $table->dropColumn(['kind', 'capacity', 'capacity_is_public', 'address', 'map_url']);
        });
    }
};
