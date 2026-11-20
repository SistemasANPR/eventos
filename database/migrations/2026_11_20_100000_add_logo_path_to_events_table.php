<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Logotipo del evento (plan 027, F1): ruta del archivo en el disco `media` (nunca una URL).
 * Nullable: un evento sin logotipo se ve como siempre. No hay datos que migrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->string('logo_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn('logo_path');
        });
    }
};
