<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Una notificación no crítica que la organización desactivó (plan 008, H-008-5, CA-008-21)
 * no se envía, pero el hecho queda registrado con el estado `disabled`. Se añade otra
 * migración: la que creó `mail_dispatches` ya está commiteada y una migración aplicada no se edita.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE mail_dispatches DROP CONSTRAINT mail_dispatches_status_check');
        DB::statement("ALTER TABLE mail_dispatches ADD CONSTRAINT mail_dispatches_status_check CHECK (status IN ('queued', 'sent', 'failed', 'throttled', 'disabled'))");
    }

    public function down(): void
    {
        DB::statement("UPDATE mail_dispatches SET status = 'failed' WHERE status = 'disabled'");
        DB::statement('ALTER TABLE mail_dispatches DROP CONSTRAINT mail_dispatches_status_check');
        DB::statement("ALTER TABLE mail_dispatches ADD CONSTRAINT mail_dispatches_status_check CHECK (status IN ('queued', 'sent', 'failed', 'throttled'))");
    }
};
