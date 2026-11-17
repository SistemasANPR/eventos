<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ejes temáticos de un evento (plan 004, H-004-2, DA-4). Un eje con propuestas
 * no se borra: se archiva. Por eso no hay `SoftDeletes` y el plan 006 enlazará
 * las propuestas con una clave foránea restrictiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('event_id');
            $table->jsonb('name');
            $table->jsonb('description')->nullable();
            $table->unsignedInteger('position');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            // Permite la clave foránea compuesta (track_id, organization_id) desde las propuestas (plan 006).
            $table->unique(['id', 'organization_id']);
            $table->foreign(['event_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('events')->restrictOnDelete();
            $table->index(['organization_id', 'event_id', 'position']);
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER tracks_organization_id_immutable
            BEFORE UPDATE OF organization_id ON tracks
            FOR EACH ROW EXECUTE FUNCTION prevent_organization_id_change()
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('tracks');
    }
};
