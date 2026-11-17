<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Formularios de propuestas de un evento (plan 005, H-005-1, ADR-0010). La definición
 * de campos vive en `fields` (jsonb) con la clave estable de cada campo; PostgreSQL solo
 * garantiza que sea un arreglo, la forma de cada campo la valida la aplicación.
 *
 * `version` sube con cada cambio de la definición (H-005-3) y las propuestas del plan
 * 006 guardarán una instantánea; por eso hay `unique(id, organization_id)` para la
 * clave foránea compuesta que las enlazará.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('event_id');
            $table->string('name', 150);
            $table->string('status', 20)->default('draft');
            $table->jsonb('fields');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['id', 'organization_id']);
            $table->foreign(['event_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('events')->restrictOnDelete();
            $table->index(['organization_id', 'event_id', 'status']);
        });

        DB::statement("ALTER TABLE form_definitions ADD CONSTRAINT form_definitions_status_check CHECK (status IN ('draft', 'published'))");
        DB::statement("ALTER TABLE form_definitions ADD CONSTRAINT form_definitions_fields_is_array CHECK (jsonb_typeof(fields) = 'array')");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER form_definitions_organization_id_immutable
            BEFORE UPDATE OF organization_id ON form_definitions
            FOR EACH ROW EXECUTE FUNCTION prevent_organization_id_change()
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('form_definitions');
    }
};
