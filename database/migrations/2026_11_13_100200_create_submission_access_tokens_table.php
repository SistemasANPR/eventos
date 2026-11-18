<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enlaces de acceso de un solo uso del autor a su propuesta (plan 006, DA-3).
 * Solo se guarda el hash SHA-256 del token: el valor en claro nunca se almacena.
 * Se consume de forma atómica con `consumed_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_access_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('submission_id');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->foreign(['submission_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('submissions')->restrictOnDelete();
            $table->index(['organization_id', 'submission_id']);
            // Limpieza de enlaces vencidos.
            $table->index('expires_at');
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER submission_access_tokens_organization_id_immutable
            BEFORE UPDATE OF organization_id ON submission_access_tokens
            FOR EACH ROW EXECUTE FUNCTION prevent_organization_id_change()
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_access_tokens');
    }
};
