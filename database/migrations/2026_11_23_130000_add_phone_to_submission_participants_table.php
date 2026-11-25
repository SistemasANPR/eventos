<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Número de teléfono del participante de una propuesta. Nulo en la base porque los participantes anteriores no lo
 * tienen; el formulario de envío lo exige a partir de ahora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submission_participants', function (Blueprint $table): void {
            $table->string('phone', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('submission_participants', function (Blueprint $table): void {
            $table->dropColumn('phone');
        });
    }
};
