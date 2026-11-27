<?php

declare(strict_types=1);

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Galería de imágenes de un hospedaje y catálogo de categorías de patrocinador (plan 030, DA-5 y DA-6).
 * ESQUEMA: crea `lodging_images` y `sponsor_categories` y añade `sponsors.sponsor_category_id`. La conversión
 * del texto libre `level` en categorías es otra migración (data) y la baja de `level`, una tercera.
 *
 * - `lodging_images`: una fila por imagen (`path` es una ruta del disco `media`, nunca una URL), ordenada por
 *   `position`. Borrar el hospedaje borra sus filas en cascada (los archivos los borra la Action).
 * - `sponsor_categories`: igual que `speaker_categories` (nombre único por evento sin distinguir mayúsculas,
 *   posición desde 1). Un patrocinador apunta a su categoría por (id, evento): la categoría es del MISMO evento.
 *   Con la categoría nula la clave compuesta no se comprueba. Borrarla la guarda `DeleteSponsorCategory`, que
 *   primero deja sin nivel a sus patrocinadores; la clave foránea queda como respaldo (`restrict`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lodging_images', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('event_id');
            $table->uuid('lodging_id');
            $table->string('path', 255);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->foreign(['event_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('events')->restrictOnDelete();
            $table->foreign(['lodging_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('lodgings')->cascadeOnDelete();
            $table->index(['lodging_id', 'position']);
        });

        Schema::create('sponsor_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('event_id');
            $table->string('name', 100);
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->unique(['id', 'organization_id']);
            // El patrocinador apunta a la categoría por (id, evento).
            $table->unique(['id', 'event_id']);
            $table->foreign(['event_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('events')->restrictOnDelete();
            $table->index(['organization_id', 'event_id', 'position']);
        });

        DB::statement('CREATE UNIQUE INDEX sponsor_categories_event_name_unique ON sponsor_categories (event_id, lower(name))');
        DB::statement('ALTER TABLE sponsor_categories ADD CONSTRAINT sponsor_categories_position_check CHECK (position >= 1)');
        DB::statement("ALTER TABLE sponsor_categories ADD CONSTRAINT sponsor_categories_name_not_blank_check CHECK (btrim(name) <> '')");

        Schema::table('sponsors', function (Blueprint $table): void {
            $table->uuid('sponsor_category_id')->nullable();
            $table->foreign(['sponsor_category_id', 'event_id'])
                ->references(['id', 'event_id'])->on('sponsor_categories')->restrictOnDelete();
            $table->index('sponsor_category_id');
        });

        foreach (['lodging_images', 'sponsor_categories'] as $table) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$table}_organization_id_immutable
                BEFORE UPDATE OF organization_id ON {$table}
                FOR EACH ROW EXECUTE FUNCTION prevent_organization_id_change()
                SQL);
            RowLevelSecurity::enable($table);
        }
    }

    public function down(): void
    {
        Schema::table('sponsors', function (Blueprint $table): void {
            $table->dropForeign(['sponsor_category_id', 'event_id']);
            $table->dropIndex(['sponsor_category_id']);
            $table->dropColumn('sponsor_category_id');
        });

        foreach (['sponsor_categories', 'lodging_images'] as $table) {
            RowLevelSecurity::disable($table);
            Schema::dropIfExists($table);
        }
    }
};
