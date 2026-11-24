<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Privacidad (plan 014, F4 y F5; `docs/database-design.md` §8.3). Aditiva y reversible.
 *
 * - `anonymization_log`: huella NO reversible de cada persona anonimizada (HMAC del correo
 *   normalizado con una sal propia de cada organización). Sirve para volver a anonimizar tras
 *   restaurar un respaldo; no guarda el correo ni ningún otro dato personal.
 * - `retention_policies`: plazo en días por categoría y organización. Sin fila no hay plazo y
 *   no se borra nada (denegar por defecto).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anonymization_log', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->char('email_hash', 64);
            // Instante UTC de la primera anonimización de esa persona en la organización.
            $table->timestamp('anonymized_at');
            $table->unique(['organization_id', 'email_hash']);
        });

        Schema::create('retention_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->string('category', 40);
            $table->unsignedInteger('retention_days');
            $table->timestamps();
            $table->unique(['organization_id', 'category']);
        });

        DB::statement("ALTER TABLE retention_policies ADD CONSTRAINT retention_policies_category_check CHECK (category IN ('unaccepted_submissions', 'reviews', 'mail_dispatches', 'audit_log', 'backups'))");
        // Entre un día y cien años: un plazo de cero borraría todo en la primera ejecución.
        DB::statement('ALTER TABLE retention_policies ADD CONSTRAINT retention_policies_days_check CHECK (retention_days BETWEEN 1 AND 36500)');

        foreach (['anonymization_log', 'retention_policies'] as $table) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$table}_organization_id_immutable
                BEFORE UPDATE OF organization_id ON {$table}
                FOR EACH ROW EXECUTE FUNCTION prevent_organization_id_change()
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_policies');
        Schema::dropIfExists('anonymization_log');
    }
};
