<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Más datos del participante de una propuesta: apellido materno, correo alternativo, celular, estado, ciudad y fotografía.
 * Nulos en la base porque los participantes anteriores no los tienen; el formulario de envío exige los que corresponde.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submission_participants', function (Blueprint $table): void {
            $table->string('second_last_name', 100)->nullable();
            $table->string('alt_email', 254)->nullable();
            $table->string('mobile', 40)->nullable();
            $table->string('state', 120)->nullable();
            $table->string('city', 120)->nullable();
            // Fotografía del participante: id del archivo (JPG o PNG) ya ligado a la propuesta.
            $table->uuid('photo_file_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('submission_participants', function (Blueprint $table): void {
            $table->dropColumn(['second_last_name', 'alt_email', 'mobile', 'state', 'city', 'photo_file_id']);
        });
    }
};
