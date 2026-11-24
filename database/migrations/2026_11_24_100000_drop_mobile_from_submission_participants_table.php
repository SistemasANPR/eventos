<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El formulario ya no pide un celular aparte: basta el teléfono con código de país. Se retira la columna `mobile`
 * (nula en todas las filas existentes, porque el campo se agregó y se quitó antes de usarse).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('submission_participants', 'mobile')) {
            Schema::table('submission_participants', function (Blueprint $table): void {
                $table->dropColumn('mobile');
            });
        }
    }

    public function down(): void
    {
        Schema::table('submission_participants', function (Blueprint $table): void {
            $table->string('mobile', 40)->nullable();
        });
    }
};
