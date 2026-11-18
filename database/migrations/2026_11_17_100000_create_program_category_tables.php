<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogos de categorías del programa de un evento (plan 021, DA-7; `docs/database-design.md` §8.1):
 * uno para ponentes y otro, distinto, para actividades. Son propios de cada evento y no se
 * relacionan con los ejes (`tracks`) ni entre sí. Las tablas pivote con ponentes y actividades
 * llegan con las migraciones de esos modelos (fases F2 y F3); por eso aquí ya existe
 * `unique(id, organization_id)`, que sus claves foráneas compuestas necesitan.
 */
return new class extends Migration
{
    private const TABLES = ['speaker_categories', 'activity_categories'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
                $table->uuid('event_id');
                $table->string('name', 100);
                $table->unsignedInteger('position');
                $table->timestamps();

                $table->unique(['id', 'organization_id']);
                $table->foreign(['event_id', 'organization_id'])
                    ->references(['id', 'organization_id'])->on('events')->restrictOnDelete();
                $table->index(['organization_id', 'event_id', 'position']);
            });

            // Un nombre por evento, sin distinguir mayúsculas (CA-021-6).
            DB::statement("CREATE UNIQUE INDEX {$name}_event_name_unique ON {$name} (event_id, lower(name))");
            DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_position_check CHECK (position >= 1)");

            DB::unprepared(<<<SQL
                CREATE TRIGGER {$name}_organization_id_immutable
                BEFORE UPDATE OF organization_id ON {$name}
                FOR EACH ROW EXECUTE FUNCTION prevent_organization_id_change()
                SQL);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $name) {
            Schema::dropIfExists($name);
        }
    }
};
