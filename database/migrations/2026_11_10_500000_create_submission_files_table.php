<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Metadatos de los archivos privados de una propuesta (plan 007, H-007-1). El
 * archivo vive en el disco privado con nombre generado; aquí solo se guarda su
 * ficha. `upload_token_hash` es el hash del token de la sesión del formulario:
 * el token en claro nunca se almacena.
 *
 * `submission_id` es un uuid sin clave foránea: la tabla `submissions` la crea
 * el plan 006, que añadirá la FK compuesta (submission_id, organization_id) en
 * su migración (deuda registrada en `docs/sprints/deuda-tecnica.md`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_files', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('event_id');
            $table->uuid('call_id');
            // Sin FK hasta que exista `submissions` (plan 006).
            $table->uuid('submission_id')->nullable();
            $table->string('form_field_key');
            $table->string('upload_token_hash', 64);
            $table->string('storage_path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->string('status')->default('pending');
            $table->timestamp('uploaded_at');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'storage_path']);
            // Para la limpieza de archivos `pending` vencidos (H-007-3).
            $table->index(['organization_id', 'status', 'uploaded_at']);
            $table->index(['organization_id', 'submission_id']);
            // Archivo, evento y convocatoria comparten organización: la base lo garantiza.
            $table->foreign(['event_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('events')->restrictOnDelete();
            $table->foreign(['call_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('call_for_submissions')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE submission_files ADD CONSTRAINT submission_files_status_check CHECK (status IN ('pending', 'attached'))");
        DB::statement('ALTER TABLE submission_files ADD CONSTRAINT submission_files_size_check CHECK (size_bytes >= 0)');
        // Un archivo está ligado a una propuesta si y solo si su estado es `attached`.
        DB::statement("ALTER TABLE submission_files ADD CONSTRAINT submission_files_attached_check CHECK ((status = 'attached') = (submission_id IS NOT NULL))");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER submission_files_organization_id_immutable
            BEFORE UPDATE OF organization_id ON submission_files
            FOR EACH ROW EXECUTE FUNCTION prevent_organization_id_change()
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_files');
    }
};
