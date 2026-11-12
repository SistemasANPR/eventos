<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de envíos de correo (plan 008, H-008-6, DA-8 y DA-9): garantiza que un mismo
 * hecho de negocio no envíe dos correos y permite el límite por destinatario y hora.
 *
 * No guarda el correo del destinatario: solo `recipient_hash`, un HMAC con la clave de la
 * aplicación, suficiente para contar envíos por dirección sin conservarla en claro. (La
 * propuesta de datos del plan no incluía esta columna; sin ella CA-008-28 no se puede
 * cumplir sin guardar el correo. Decisión registrada en el plan 008, §9.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_dispatches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->string('notification_key', 100);
            $table->string('idempotency_key', 255);
            $table->char('recipient_hash', 64);
            $table->string('status', 20)->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'idempotency_key']);
            $table->index(['organization_id', 'notification_key', 'recipient_hash', 'created_at'], 'mail_dispatches_throttle_index');
        });

        DB::statement("ALTER TABLE mail_dispatches ADD CONSTRAINT mail_dispatches_status_check CHECK (status IN ('queued', 'sent', 'failed', 'throttled'))");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER mail_dispatches_organization_id_immutable
            BEFORE UPDATE OF organization_id ON mail_dispatches
            FOR EACH ROW EXECUTE FUNCTION prevent_organization_id_change()
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_dispatches');
    }
};
