<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tipos de propuesta propios de cada evento (plan 004, DA-7). Sustituyen a la
 * lista fija `session`, `panel`, `poster`, `workshop`: cada evento existente
 * recibe esos cuatro como tipos editables y las convocatorias que ya tenían un
 * tipo quedan enlazadas al equivalente. No se borran tipos, se archivan.
 */
return new class extends Migration
{
    /** Posición de cada tipo por defecto; coincide con `App\Support\Calls\DefaultSubmissionTypes`. */
    private const LEGACY_POSITIONS = ['session' => 1, 'panel' => 2, 'poster' => 3, 'workshop' => 4];

    public function up(): void
    {
        Schema::create('submission_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('event_id');
            $table->jsonb('name');
            $table->unsignedInteger('position');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            // Permite la clave foránea compuesta (submission_type_id, organization_id) desde las convocatorias.
            $table->unique(['id', 'organization_id']);
            $table->foreign(['event_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('events')->restrictOnDelete();
            $table->index(['organization_id', 'event_id', 'position']);
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER submission_types_organization_id_immutable
            BEFORE UPDATE OF organization_id ON submission_types
            FOR EACH ROW EXECUTE FUNCTION prevent_organization_id_change()
            SQL);

        $this->seedDefaultTypesForExistingEvents();

        Schema::table('call_for_submissions', function (Blueprint $table): void {
            $table->uuid('submission_type_id')->nullable()->after('slug');
        });

        $this->linkExistingCalls();

        DB::statement('ALTER TABLE call_for_submissions ALTER COLUMN submission_type_id SET NOT NULL');
        DB::statement('ALTER TABLE call_for_submissions DROP CONSTRAINT call_for_submissions_type_check');

        Schema::table('call_for_submissions', function (Blueprint $table): void {
            $table->foreign(['submission_type_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('submission_types')->restrictOnDelete();
            $table->dropColumn('submission_type');
        });
    }

    /**
     * Restaura la columna de texto: un tipo creado por el organizador no tiene
     * equivalente y se degrada a `session`.
     */
    public function down(): void
    {
        Schema::table('call_for_submissions', function (Blueprint $table): void {
            $table->string('submission_type')->nullable()->after('slug');
        });

        DB::statement("UPDATE call_for_submissions c SET submission_type = CASE st.position WHEN 2 THEN 'panel' WHEN 3 THEN 'poster' WHEN 4 THEN 'workshop' ELSE 'session' END FROM submission_types st WHERE st.id = c.submission_type_id AND st.organization_id = c.organization_id");
        DB::statement("UPDATE call_for_submissions SET submission_type = 'session' WHERE submission_type IS NULL");
        DB::statement('ALTER TABLE call_for_submissions ALTER COLUMN submission_type SET NOT NULL');
        DB::statement("ALTER TABLE call_for_submissions ADD CONSTRAINT call_for_submissions_type_check CHECK (submission_type IN ('session', 'panel', 'poster', 'workshop'))");

        Schema::table('call_for_submissions', function (Blueprint $table): void {
            $table->dropForeign(['submission_type_id', 'organization_id']);
            $table->dropColumn('submission_type_id');
        });

        Schema::dropIfExists('submission_types');
    }

    private function seedDefaultTypesForExistingEvents(): void
    {
        $names = [
            1 => ['es' => 'Sesión', 'en' => 'Session', 'pt' => 'Sessão'],
            2 => ['es' => 'Panel', 'en' => 'Panel', 'pt' => 'Painel'],
            3 => ['es' => 'Póster', 'en' => 'Poster', 'pt' => 'Pôster'],
            4 => ['es' => 'Taller', 'en' => 'Workshop', 'pt' => 'Oficina'],
        ];

        foreach ($names as $position => $name) {
            DB::insert(
                'INSERT INTO submission_types (id, organization_id, event_id, name, position, created_at, updated_at) '
                .'SELECT gen_random_uuid(), e.organization_id, e.id, ?::jsonb, ?, now(), now() FROM events e',
                [json_encode($name, JSON_UNESCAPED_UNICODE), $position],
            );
        }
    }

    private function linkExistingCalls(): void
    {
        foreach (self::LEGACY_POSITIONS as $legacy => $position) {
            DB::update(
                'UPDATE call_for_submissions c SET submission_type_id = st.id FROM submission_types st '
                .'WHERE st.event_id = c.event_id AND st.organization_id = c.organization_id AND st.position = ? AND c.submission_type = ?',
                [$position, $legacy],
            );
        }
    }
};
