<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Support\Files\MediaDisk;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Preguntas del formulario de hospedajes y de patrocinadores (plan 030, DA-7, F4): se definen UNA VEZ por evento y módulo
 * (`scope` = `lodging` o `sponsor`) y todos los registros del evento las muestran como campos del formulario. Cada una
 * tiene un título (`label`), un tipo (texto corto o largo, número, fecha, casilla, opción única o múltiple, foto o archivo PDF),
 * si es obligatoria (`required`), su `config` (opciones de una pregunta de elección; mínimo y máximo de un número) y `position`
 * (desde 1), que ordena los campos en el formulario.
 *
 * Los valores de cada registro siguen en `lodgings.extra_fields` / `sponsors.extra_fields` (JSON), pero ahora como mapa
 * `{id de la pregunta: valor | {"path": "ruta del disco media"}}`. Esta migración CONVIERTE lo que dejó el formato
 * anterior (lista `{id, label, type, value|path}` por registro, plan 030 F4 inicial): por evento y módulo crea una pregunta por
 * cada par (título sin distinguir mayúsculas, tipo texto corto o foto) —hasta 10— y reubica en ellas los valores. Lo que no cabe (más de
 * 10 preguntas distintas en un evento y módulo) se descarta y sus fotos se borran del disco. `down` borra la tabla y deja
 * `extra_fields` en nulo (el formato anterior no se puede reconstruir).
 */
return new class extends Migration
{
    private const MAX_QUESTIONS = 10;

    public function up(): void
    {
        Schema::create('extra_field_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('event_id');
            $table->string('scope', 20);
            $table->string('label', 120);
            $table->string('type', 20);
            $table->boolean('required')->default(false);
            $table->json('config')->nullable();
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->unique(['id', 'organization_id']);
            $table->foreign(['event_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('events')->restrictOnDelete();
            $table->index(['organization_id', 'event_id', 'scope', 'position'], 'extra_field_definitions_event_scope_index');
        });

        DB::statement("ALTER TABLE extra_field_definitions ADD CONSTRAINT extra_field_definitions_scope_check CHECK (scope IN ('lodging', 'sponsor'))");
        DB::statement("ALTER TABLE extra_field_definitions ADD CONSTRAINT extra_field_definitions_type_check CHECK (type IN ('short_text', 'long_text', 'number', 'date', 'checkbox', 'single_choice', 'multiple_choice', 'photo', 'file'))");
        DB::statement('ALTER TABLE extra_field_definitions ADD CONSTRAINT extra_field_definitions_position_check CHECK (position >= 1)');
        DB::statement("ALTER TABLE extra_field_definitions ADD CONSTRAINT extra_field_definitions_label_not_blank_check CHECK (btrim(label) <> '')");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER extra_field_definitions_organization_id_immutable
            BEFORE UPDATE OF organization_id ON extra_field_definitions
            FOR EACH ROW EXECUTE FUNCTION prevent_organization_id_change()
            SQL);
        RowLevelSecurity::enable('extra_field_definitions');

        // Organización por organización, con su contexto puesto, para que RLS deje ver y escribir sus filas.
        Organization::query()->each(function (Organization $organization): void {
            CurrentOrganization::run($organization, function (): void {
                $this->convert('lodgings', 'lodging');
                $this->convert('sponsors', 'sponsor');
            });
        });
    }

    public function down(): void
    {
        Organization::query()->each(function (Organization $organization): void {
            CurrentOrganization::run($organization, function (): void {
                foreach (['lodgings', 'sponsors'] as $table) {
                    DB::table($table)->update(['extra_fields' => null]);
                }
            });
        });

        RowLevelSecurity::disable('extra_field_definitions');
        Schema::dropIfExists('extra_field_definitions');
    }

    /** Convierte el formato anterior (lista por registro) al mapa por pregunta del evento. */
    private function convert(string $table, string $scope): void
    {
        $rows = DB::table($table)->whereNotNull('extra_fields')->get(['id', 'organization_id', 'event_id', 'extra_fields']);
        /** @var array<string, array<string, string>> $definitions  "evento" => ["título|tipo" => id de la pregunta] */
        $definitions = [];

        foreach ($rows as $row) {
            $items = json_decode((string) $row->extra_fields, true);

            if (! is_array($items) || ! array_is_list($items)) {
                continue;
            }

            $map = [];

            foreach ($items as $item) {
                if (! is_array($item) || ! is_string($item['label'] ?? null)) {
                    continue;
                }

                $type = ($item['type'] ?? null) === 'image' ? 'photo' : 'short_text';
                $value = $type === 'photo'
                    ? (is_string($item['path'] ?? null) ? ['path' => $item['path']] : null)
                    : (is_string($item['value'] ?? null) && $item['value'] !== '' ? $item['value'] : null);
                $key = mb_strtolower(trim($item['label'])).'|'.$type;
                $known = $definitions[$row->event_id] ?? [];

                if (! isset($known[$key])) {
                    if (count($known) >= self::MAX_QUESTIONS) {
                        // No cabe: se descarta el valor y, si es una foto, también su archivo.
                        if (is_array($value)) {
                            $this->deleteFile($value['path']);
                        }

                        continue;
                    }

                    $id = (string) Str::uuid();
                    DB::table('extra_field_definitions')->insert([
                        'id' => $id,
                        'organization_id' => $row->organization_id,
                        'event_id' => $row->event_id,
                        'scope' => $scope,
                        'label' => mb_substr(trim($item['label']), 0, 120),
                        'type' => $type,
                        'required' => false,
                        'position' => count($known) + 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $definitions[$row->event_id][$key] = $id;
                }

                if ($value !== null) {
                    $map[$definitions[$row->event_id][$key]] = $value;
                }
            }

            DB::table($table)->where('id', $row->id)->update(['extra_fields' => $map === [] ? null : json_encode($map, JSON_UNESCAPED_UNICODE)]);
        }
    }

    private function deleteFile(string $path): void
    {
        try {
            MediaDisk::get()->delete($path);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
};
