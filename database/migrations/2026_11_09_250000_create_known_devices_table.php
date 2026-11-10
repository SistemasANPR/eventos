<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dispositivos desde los que cada usuario ya inició sesión (plan 001, CA-001-35).
 * Guarda solo la huella en hash, nunca el agente de usuario ni la IP. Es de
 * ámbito plataforma: pertenece al usuario, que puede estar en varias
 * organizaciones. El `id` lo genera la base para poder registrar el
 * dispositivo con una sola sentencia `INSERT ... ON CONFLICT DO NOTHING`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('known_devices', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('fingerprint_hash', 64);
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['user_id', 'fingerprint_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('known_devices');
    }
};
