<?php

declare(strict_types=1);

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Responsables de una actividad en un lugar externo (plan 035, DA-5 y DA-11; `docs/database-design.md` §8.5):
 * nombre, teléfono y correo de quien atiende al público. Son datos personales: `is_public` los deja solo para
 * el equipo salvo que se marquen, se localizan por correo para la anonimización (`lower(email)`) y se
 * eliminan con la actividad (cascada) o al anonimizar a la persona. La clave compuesta impide que un
 * responsable cuelgue de una actividad de otro evento u organización. Nace con RLS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_contacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('event_id');
            $table->uuid('activity_id');
            $table->string('name', 120);
            $table->string('phone', 40)->nullable();
            $table->string('email', 254)->nullable();
            $table->unsignedSmallInteger('position');
            $table->boolean('is_public')->default(false);
            $table->timestamps();

            $table->unique(['id', 'organization_id']);
            $table->unique(['activity_id', 'position']);
            $table->foreign(['event_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('events')->restrictOnDelete();
            $table->foreign(['activity_id', 'event_id', 'organization_id'])
                ->references(['id', 'event_id', 'organization_id'])->on('event_activities')->cascadeOnDelete();
            $table->index(['organization_id', 'event_id', 'activity_id']);
        });

        DB::statement('ALTER TABLE activity_contacts ADD CONSTRAINT activity_contacts_position_check CHECK (position >= 1)');
        // La anonimización localiza a la persona por su correo normalizado, dentro de una organización.
        DB::statement('CREATE INDEX activity_contacts_email_idx ON activity_contacts (organization_id, lower(email))');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER activity_contacts_organization_id_immutable
            BEFORE UPDATE OF organization_id ON activity_contacts
            FOR EACH ROW EXECUTE FUNCTION prevent_organization_id_change()
            SQL);

        RowLevelSecurity::enable('activity_contacts');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('activity_contacts');
        Schema::dropIfExists('activity_contacts');
    }
};
