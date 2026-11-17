<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Propuestas de un evento (plan 006, §4; incluye las columnas del plan 018 para
 * conferencistas invitados). Una propuesta es EVIDENCIA: se conserva (SoftDeletes),
 * guarda el aviso de privacidad aceptado y una instantánea del formulario con que
 * se envió. El origen decide qué columnas son obligatorias: `call` (envío público a
 * una convocatoria) o `invited` (alta por el organizador, sin convocatoria).
 *
 * Todas las claves foráneas son compuestas con `organization_id`: la base impide
 * que una propuesta de la organización A apunte a datos de B. Con columna nula
 * (convocatoria, aviso) PostgreSQL no comprueba la clave, como se espera.
 *
 * `submission_files.submission_id` (plan 007) sigue sin clave foránea: la deuda
 * DT-12 la cierra una decisión aparte, no esta migración.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('event_id');
            $table->string('origin', 20)->default('call');
            // Nula solo en `invited` (CHECK de coherencia).
            $table->uuid('call_for_submissions_id')->nullable();
            $table->uuid('submission_type_id');
            $table->foreignUuid('registered_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('authorization_declared_at')->nullable();
            $table->uuid('form_definition_id');
            $table->unsignedInteger('form_version');
            $table->jsonb('form_snapshot');
            $table->uuid('track_id');
            $table->string('title', 200);
            $table->jsonb('answers');
            $table->string('status', 20)->default('submitted');
            $table->string('locale', 5);
            // Idempotencia del envío público; los invitados no la usan.
            $table->string('idempotency_key', 100)->nullable();
            $table->string('payload_hash', 64)->nullable();
            // Cada fila de `privacy_notices` es una versión del aviso.
            $table->uuid('privacy_notice_version_id')->nullable();
            $table->timestamp('consented_at')->nullable();
            $table->timestamp('third_parties_authorized_at')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('withdrawn_at')->nullable();
            // Bloqueo optimista de las ediciones del autor.
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            // Permite las claves foráneas compuestas desde participantes y tokens.
            $table->unique(['id', 'organization_id']);
            $table->unique(['organization_id', 'idempotency_key']);

            $table->foreign(['event_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('events')->restrictOnDelete();
            $table->foreign(['call_for_submissions_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('call_for_submissions')->restrictOnDelete();
            $table->foreign(['submission_type_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('submission_types')->restrictOnDelete();
            $table->foreign(['form_definition_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('form_definitions')->restrictOnDelete();
            // Restrictiva: un eje con propuestas no se borra (plan 004, DA-4; CA-006-17).
            $table->foreign(['track_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('tracks')->restrictOnDelete();
            $table->foreign(['privacy_notice_version_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('privacy_notices')->restrictOnDelete();

            $table->index(['organization_id', 'event_id', 'call_for_submissions_id', 'status']);
            $table->index(['organization_id', 'track_id']);
            // Lista de propuestas e invitados por origen (plan 018).
            $table->index(['organization_id', 'event_id', 'origin', 'status']);
        });

        DB::statement("ALTER TABLE submissions ADD CONSTRAINT submissions_status_check CHECK (status IN ('submitted', 'under_review', 'accepted', 'waitlisted', 'rejected', 'confirmed', 'withdrawn'))");
        DB::statement("ALTER TABLE submissions ADD CONSTRAINT submissions_origin_check CHECK (origin IN ('call', 'invited'))");
        DB::statement(<<<'SQL'
            ALTER TABLE submissions ADD CONSTRAINT submissions_origin_columns_check CHECK (
                (origin = 'call'
                    AND call_for_submissions_id IS NOT NULL
                    AND consented_at IS NOT NULL
                    AND privacy_notice_version_id IS NOT NULL
                    AND idempotency_key IS NOT NULL
                    AND payload_hash IS NOT NULL
                    AND registered_by_user_id IS NULL
                    AND authorization_declared_at IS NULL)
                OR
                (origin = 'invited'
                    AND call_for_submissions_id IS NULL
                    AND consented_at IS NULL
                    AND privacy_notice_version_id IS NULL
                    AND registered_by_user_id IS NOT NULL
                    AND authorization_declared_at IS NOT NULL)
            )
            SQL);
        DB::statement('ALTER TABLE submissions ADD CONSTRAINT submissions_form_snapshot_size_check CHECK (octet_length(form_snapshot::text) <= 65536)');
        DB::statement('ALTER TABLE submissions ADD CONSTRAINT submissions_version_check CHECK (version >= 1)');
        // `withdrawn_at` existe si y solo si la propuesta está retirada.
        DB::statement("ALTER TABLE submissions ADD CONSTRAINT submissions_withdrawn_check CHECK ((status = 'withdrawn') = (withdrawn_at IS NOT NULL))");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER submissions_organization_id_immutable
            BEFORE UPDATE OF organization_id ON submissions
            FOR EACH ROW EXECUTE FUNCTION prevent_organization_id_change()
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};
