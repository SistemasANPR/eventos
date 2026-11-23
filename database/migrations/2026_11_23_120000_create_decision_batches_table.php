<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lotes de decisión masiva (plan 012, §4 propuesta de datos). La tabla se crea en F1 porque el historial de
 * estados (`submission_status_changes.decision_batch_id`) la referencia; su modelo y la decisión masiva
 * llegan en F2. Solo se añade: un lote no se edita ni se borra. Ámbito: organización y evento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decision_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('event_id');
            $table->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete();
            // Filtro de la selección (se audita junto con el total).
            $table->jsonb('filter');
            $table->unsignedInteger('total');
            // Instante UTC; el lote no se modifica.
            $table->timestamp('created_at');

            $table->unique(['id', 'organization_id']);
            $table->foreign(['event_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('events')->restrictOnDelete();
            $table->index(['organization_id', 'event_id', 'created_at']);
        });

        DB::statement('ALTER TABLE decision_batches ADD CONSTRAINT decision_batches_total_check CHECK (total >= 1)');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER decision_batches_organization_id_immutable
            BEFORE UPDATE OF organization_id ON decision_batches
            FOR EACH ROW EXECUTE FUNCTION prevent_organization_id_change()
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('decision_batches');
    }
};
