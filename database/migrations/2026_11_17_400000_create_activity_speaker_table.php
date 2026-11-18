<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ponentes de cada actividad (plan 021, DA-8, CA-021-5; `docs/database-design.md` §8.1): relación
 * muchos a muchos entre `event_activities` y `speakers`. El pivote lleva la organización y el evento
 * y exige, con claves foráneas compuestas, que actividad y ponente sean del MISMO evento y de la misma
 * organización; la clave primaria impide repetir el par. `position` ordena a los ponentes dentro de la
 * actividad (0 por defecto). Borrar (físicamente) cualquiera de los dos lados limpia el vínculo; el
 * borrado lógico no lo toca y las consultas ya excluyen lo borrado.
 *
 * `speakers` gana aquí `unique(id, event_id, organization_id)` para poder apuntar a ella con esa clave.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('speakers', function (Blueprint $table): void {
            $table->unique(['id', 'event_id', 'organization_id']);
        });

        Schema::create('activity_speaker', function (Blueprint $table): void {
            $table->uuid('activity_id');
            $table->uuid('speaker_id');
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('event_id');
            $table->unsignedSmallInteger('position')->default(0);

            $table->primary(['activity_id', 'speaker_id']);

            $table->foreign(['activity_id', 'event_id', 'organization_id'])
                ->references(['id', 'event_id', 'organization_id'])->on('event_activities')->cascadeOnDelete();
            $table->foreign(['speaker_id', 'event_id', 'organization_id'])
                ->references(['id', 'event_id', 'organization_id'])->on('speakers')->cascadeOnDelete();

            // Las actividades de un ponente.
            $table->index('speaker_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_speaker');

        Schema::table('speakers', function (Blueprint $table): void {
            $table->dropUnique(['id', 'event_id', 'organization_id']);
        });
    }
};
