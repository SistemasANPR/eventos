<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de estados de una propuesta (plan 012, §4 propuesta de datos; ADR-0005 DA-2): dato de negocio
 * consultable, distinto de la bitácora de auditoría. Solo se añade: sin `updated_at` ni borrado físico.
 *
 * La propuesta y el evento van por clave foránea compuesta `(propuesta, evento, organización)`, así que la
 * base impide un cambio de otra organización o de otro evento. `actor_user_id` es nulo solo cuando lo
 * hace el sistema (un trabajo programado). Los comentarios se limitan a 1000 caracteres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_status_changes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('event_id');
            $table->uuid('submission_id');
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            // Motivo interno (por ejemplo, de una reversa); no se envía al autor.
            $table->text('comment')->nullable();
            // Comentario opcional para el autor (texto plano).
            $table->text('author_comment')->nullable();
            $table->uuid('decision_batch_id')->nullable();
            // Instante UTC.
            $table->timestamp('created_at');

            $table->foreign(['submission_id', 'event_id', 'organization_id'])
                ->references(['id', 'event_id', 'organization_id'])->on('submissions')->restrictOnDelete();
            $table->foreign(['decision_batch_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('decision_batches')->restrictOnDelete();

            $table->index(['organization_id', 'submission_id', 'created_at']);
            $table->index(['organization_id', 'decision_batch_id']);
        });

        $statuses = "'submitted', 'under_review', 'accepted', 'waitlisted', 'rejected', 'confirmed', 'withdrawn'";
        DB::statement("ALTER TABLE submission_status_changes ADD CONSTRAINT submission_status_changes_from_check CHECK (from_status IN ({$statuses}))");
        DB::statement("ALTER TABLE submission_status_changes ADD CONSTRAINT submission_status_changes_to_check CHECK (to_status IN ({$statuses}))");
        DB::statement('ALTER TABLE submission_status_changes ADD CONSTRAINT submission_status_changes_distinct_check CHECK (from_status <> to_status)');
        DB::statement('ALTER TABLE submission_status_changes ADD CONSTRAINT submission_status_changes_comment_check CHECK (char_length(comment) <= 1000 AND char_length(author_comment) <= 1000)');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER submission_status_changes_organization_id_immutable
            BEFORE UPDATE OF organization_id ON submission_status_changes
            FOR EACH ROW EXECUTE FUNCTION prevent_organization_id_change()
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_status_changes');
    }
};
