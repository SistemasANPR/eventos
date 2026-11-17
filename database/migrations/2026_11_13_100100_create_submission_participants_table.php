<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Participantes de una propuesta (plan 006, DA-2): instantánea de sus datos en el
 * momento del envío, vinculada a un `Contact` por correo sin sobrescribirlo.
 * Como máximo uno es el principal (índice parcial).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_participants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('submission_id');
            $table->uuid('contact_id');
            $table->unsignedSmallInteger('position');
            $table->boolean('is_primary')->default(false);
            $table->string('full_name', 120);
            $table->string('email', 254);
            // ISO 3166-1 alfa-2.
            $table->char('country', 2);
            $table->string('job_title', 120)->nullable();
            $table->string('company', 160)->nullable();
            $table->string('bio', 2000)->nullable();
            $table->timestamps();

            $table->unique(['submission_id', 'position']);
            $table->foreign(['submission_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('submissions')->restrictOnDelete();
            $table->foreign(['contact_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('contacts')->restrictOnDelete();
            $table->index(['organization_id', 'contact_id']);
        });

        // Un solo participante principal por propuesta.
        DB::statement('CREATE UNIQUE INDEX submission_participants_one_primary ON submission_participants (submission_id) WHERE is_primary');
        // Búsqueda del autor por correo al pedir un enlace de acceso (plan 006, CA-006-30).
        DB::statement('CREATE INDEX submission_participants_organization_email_index ON submission_participants (organization_id, lower(email))');
        DB::statement('ALTER TABLE submission_participants ADD CONSTRAINT submission_participants_position_check CHECK (position >= 1)');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER submission_participants_organization_id_immutable
            BEFORE UPDATE OF organization_id ON submission_participants
            FOR EACH ROW EXECUTE FUNCTION prevent_organization_id_change()
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_participants');
    }
};
