<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Landing del evento: una fila por evento con su borrador y su versión publicada, ambos como arreglo
 * `jsonb` de bloques (plan 022, DA-4; `docs/database-design.md` §4.1 del plan). La forma de cada bloque
 * la valida `LandingBlockRules`; la base solo garantiza que sea un arreglo y su tamaño. Clave foránea
 * compuesta `(event_id, organization_id)`: la landing y su evento son de la misma organización.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_landings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('event_id')->unique();
            $table->jsonb('draft_blocks');
            $table->jsonb('published_blocks')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampTz('published_at')->nullable();
            $table->foreignUuid('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Permite las claves foráneas compuestas desde `landing_images`.
            $table->unique(['id', 'organization_id']);
            $table->unique(['id', 'event_id', 'organization_id']);

            $table->foreign(['event_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('events')->restrictOnDelete();
        });

        // Ambos son arreglos de a lo sumo 30 bloques y 128 KB (DA-14); la versión publicada y su fecha van juntas.
        DB::statement("ALTER TABLE event_landings ADD CONSTRAINT event_landings_draft_blocks_check CHECK (jsonb_typeof(draft_blocks) = 'array' AND jsonb_array_length(draft_blocks) <= 30 AND octet_length(draft_blocks::text) <= 131072)");
        DB::statement("ALTER TABLE event_landings ADD CONSTRAINT event_landings_published_blocks_check CHECK (published_blocks IS NULL OR (jsonb_typeof(published_blocks) = 'array' AND jsonb_array_length(published_blocks) <= 30 AND octet_length(published_blocks::text) <= 131072))");
        DB::statement('ALTER TABLE event_landings ADD CONSTRAINT event_landings_published_together_check CHECK ((published_at IS NULL) = (published_blocks IS NULL))');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER event_landings_organization_id_immutable
            BEFORE UPDATE OF organization_id ON event_landings
            FOR EACH ROW EXECUTE FUNCTION prevent_organization_id_change()
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('event_landings');
    }
};
