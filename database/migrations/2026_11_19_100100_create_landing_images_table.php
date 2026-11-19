<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Imágenes de la landing del evento (plan 022, DA-7): el archivo vive en el disco privado `media` y
 * los bloques guardan solo el identificador de esta fila. Las claves foráneas compuestas garantizan que
 * la imagen, su landing y su evento son de la misma organización (y la landing, de ese evento).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_images', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('event_id');
            $table->uuid('event_landing_id');
            $table->string('path');
            $table->string('mime', 50);
            $table->unsignedInteger('size_bytes');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->timestampTz('created_at')->nullable();
            $table->softDeletes();

            $table->foreign(['event_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('events')->restrictOnDelete();
            $table->foreign(['event_landing_id', 'event_id', 'organization_id'])
                ->references(['id', 'event_id', 'organization_id'])->on('event_landings')->restrictOnDelete();

            $table->index(['organization_id', 'event_id', 'event_landing_id']);
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER landing_images_organization_id_immutable
            BEFORE UPDATE OF organization_id ON landing_images
            FOR EACH ROW EXECUTE FUNCTION prevent_organization_id_change()
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_images');
    }
};
