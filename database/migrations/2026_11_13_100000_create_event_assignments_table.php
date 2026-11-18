<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Asignaciones de usuarios a eventos con rol (plan 011, H-011-7; propuesta de
 * datos §4.2b aprobada el 2026-11-13). Las claves compuestas garantizan en la
 * base que evento y usuario pertenecen a la organización de la asignación; el
 * estado `deactivated` de la membresía lo vigilan la Action y la Policy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('event_id');
            $table->uuid('user_id');
            $table->string('role', 20);
            $table->timestamps();

            // Permite claves foráneas compuestas (assignment_id, organization_id) desde las tablas de evaluación.
            $table->unique(['id', 'organization_id']);
            $table->unique(['event_id', 'user_id', 'role']);
            $table->foreign(['event_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('events')->restrictOnDelete();
            $table->foreign(['organization_id', 'user_id'])
                ->references(['organization_id', 'user_id'])->on('organization_user')->restrictOnDelete();
            $table->index(['organization_id', 'user_id']);
        });

        // `speaker` llega en R2: ampliar la lista es una migración futura.
        DB::statement("ALTER TABLE event_assignments ADD CONSTRAINT event_assignments_role_check CHECK (role IN ('reviewer', 'coordinator'))");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER event_assignments_organization_id_immutable
            BEFORE UPDATE OF organization_id ON event_assignments
            FOR EACH ROW EXECUTE FUNCTION prevent_organization_id_change()
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('event_assignments');
    }
};
