<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verificación en dos pasos (plan 014, H-014-1; propuesta aprobada de `docs/database-design.md` §6).
 * Ámbito plataforma: vive en `users`, no en una organización. Solo agrega columnas nulas, sin tocar
 * lo existente.
 *
 * - `app_authentication_secret`: secreto TOTP, cifrado por el modelo (`encrypted`).
 * - `app_authentication_recovery_codes`: códigos de recuperación; el modelo los guarda con hash
 *   y cifrados (`encrypted:array`).
 * - `two_factor_confirmed_at`: cuándo se confirmó con un código válido (UTC); es la fuente de
 *   `User::hasConfirmedTwoFactor()`.
 *
 * Al aplicarse en una base real, todo usuario existente queda sin 2FA y se le fuerza a configurarla
 * en su siguiente inicio de sesión (si `TWO_FACTOR_REQUIRED` está encendido).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('app_authentication_secret')->nullable();
            $table->text('app_authentication_recovery_codes')->nullable();
            $table->timestampTz('two_factor_confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['app_authentication_secret', 'app_authentication_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
