<?php

declare(strict_types=1);

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hospedaje y patrocinadores de un evento (plan 030, DA-1). Cada ficha es de UN evento y va por clave
 * foránea compuesta con la organización, igual que `speakers`. No guardan datos personales. El enlace y
 * el sitio web los valida la Action (solo http y https); `logo_path` es una ruta del disco `media`, nunca
 * una URL. Las dos tablas nacen con RLS (`RowLevelSecurity::enable`), como exige la guardiana del plan 014.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lodgings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('event_id');
            $table->string('name', 160);
            $table->string('address', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('booking_url', 2048)->nullable();
            // Tarifa o código de descuento, como texto informativo.
            $table->string('rate', 160)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['id', 'organization_id']);
            $table->foreign(['event_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('events')->restrictOnDelete();
            $table->index(['organization_id', 'event_id', 'position']);
        });

        Schema::create('sponsors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('event_id');
            $table->string('name', 160);
            // Nivel libre: Oro, Plata, Bronce u otro que defina el equipo.
            $table->string('level', 80)->nullable();
            $table->string('website_url', 2048)->nullable();
            $table->string('logo_path', 255)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['id', 'organization_id']);
            $table->foreign(['event_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('events')->restrictOnDelete();
            $table->index(['organization_id', 'event_id', 'position']);
        });

        foreach (['lodgings', 'sponsors'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_name_not_blank_check CHECK (btrim(name) <> '')");
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
        foreach (['sponsors', 'lodgings'] as $table) {
            RowLevelSecurity::disable($table);
        }

        Schema::dropIfExists('sponsors');
        Schema::dropIfExists('lodgings');
    }
};
