<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Formulario de propuestas de la convocatoria (plan 005, H-005-3, CA-005-16). Es nulo
 * hasta que se publica un formulario. La clave foránea compuesta con
 * `organization_id` impide que una convocatoria apunte al formulario de otra
 * organización; que sea del mismo evento lo comprueba `PublishFormDefinition`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_for_submissions', function (Blueprint $table): void {
            $table->uuid('form_definition_id')->nullable();

            $table->foreign(['form_definition_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('form_definitions')->restrictOnDelete();
            $table->index('form_definition_id');
        });
    }

    public function down(): void
    {
        Schema::table('call_for_submissions', function (Blueprint $table): void {
            $table->dropForeign(['form_definition_id', 'organization_id']);
            $table->dropIndex(['form_definition_id']);
            $table->dropColumn('form_definition_id');
        });
    }
};
