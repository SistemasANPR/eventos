<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Avisos de privacidad por evento, una fila por versión (plan 003, DA-4).
 * `body` es un JSON con el texto de cada idioma. Una versión publicada es
 * EVIDENCIA de qué texto aceptó cada persona: la base impide modificarla o
 * borrarla (la única transición permitida es publicar un borrador).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacy_notices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('event_id');
            $table->unsignedInteger('version');
            $table->jsonb('body');
            $table->timestamp('published_at')->nullable();
            $table->foreignUuid('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['event_id', 'version']);
            // Permite claves foráneas compuestas desde tablas hijas (consentimientos, plan 006).
            $table->unique(['id', 'organization_id']);
            // Aviso y evento comparten organización: la base lo garantiza, además de la aplicación.
            $table->foreign(['event_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('events')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE privacy_notices ADD CONSTRAINT privacy_notices_version_check CHECK (version >= 1)');
        // Publicar fija fecha y persona a la vez; un borrador no tiene ninguna.
        DB::statement('ALTER TABLE privacy_notices ADD CONSTRAINT privacy_notices_published_check CHECK ((published_at IS NULL) = (published_by IS NULL))');
        // Un evento tiene como máximo UN borrador abierto a la vez.
        DB::statement('CREATE UNIQUE INDEX privacy_notices_one_draft_per_event ON privacy_notices (event_id) WHERE published_at IS NULL');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_published_privacy_notice_change() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.published_at IS NOT NULL THEN
                    RAISE EXCEPTION 'El aviso de privacidad publicado no se puede modificar ni borrar (versión %)', OLD.version
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;

                RETURN NEW;
            END;
            $$
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER privacy_notices_published_immutable
            BEFORE UPDATE OR DELETE ON privacy_notices
            FOR EACH ROW EXECUTE FUNCTION prevent_published_privacy_notice_change()
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER privacy_notices_organization_id_immutable
            BEFORE UPDATE OF organization_id ON privacy_notices
            FOR EACH ROW EXECUTE FUNCTION prevent_organization_id_change()
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('privacy_notices');
        DB::unprepared('DROP FUNCTION IF EXISTS prevent_published_privacy_notice_change()');
    }
};
