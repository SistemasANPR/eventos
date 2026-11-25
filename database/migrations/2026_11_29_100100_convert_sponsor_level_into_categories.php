<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MIGRACIÓN DE DATOS (separada de las de esquema): el texto libre `sponsors.level` pasa a ser una categoría
 * del evento (plan 030, DA-6). Por cada evento, cada valor distinto (sin distinguir mayúsculas ni espacios
 * sobrantes) crea una categoría con la primera grafía vista, en el orden en que aparece por el orden del
 * patrocinador, y cada patrocinador queda asignado a la suya. Se recorre organización por organización con su
 * contexto puesto, para que RLS deje ver y escribir sus filas. Es idempotente: no repite una categoría que ya existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Organization::query()->each(function (Organization $organization): void {
            CurrentOrganization::run($organization, function (): void {
                $rows = DB::table('sponsors')
                    ->whereNotNull('level')
                    ->whereRaw("btrim(level) <> ''")
                    ->whereNull('sponsor_category_id')
                    ->orderBy('event_id')->orderBy('position')->orderBy('created_at')
                    ->get(['id', 'organization_id', 'event_id', 'level']);

                /** @var array<string, array<string, string>> $categories evento => (nombre en minúsculas => id) */
                $categories = [];

                foreach ($rows as $row) {
                    $name = trim((string) $row->level);
                    $key = mb_strtolower($name);
                    $existing = $categories[$row->event_id] ??= DB::table('sponsor_categories')
                        ->where('event_id', $row->event_id)
                        ->get(['id', 'name'])
                        ->mapWithKeys(fn (object $category): array => [mb_strtolower((string) $category->name) => (string) $category->id])
                        ->all();

                    if (! isset($existing[$key])) {
                        $id = (string) Str::uuid();
                        DB::table('sponsor_categories')->insert([
                            'id' => $id,
                            'organization_id' => $row->organization_id,
                            'event_id' => $row->event_id,
                            'name' => mb_substr($name, 0, 100),
                            'position' => count($existing) + 1,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $categories[$row->event_id][$key] = $id;
                    }

                    DB::table('sponsors')->where('id', $row->id)->update(['sponsor_category_id' => $categories[$row->event_id][$key]]);
                }
            });
        });
    }

    public function down(): void
    {
        // Devuelve el nombre de la categoría al texto libre y deja a los patrocinadores sin categoría;
        // las categorías mismas las borra la reversa de la migración de esquema.
        Organization::query()->each(function (Organization $organization): void {
            CurrentOrganization::run($organization, function (): void {
                DB::statement(
                    'UPDATE sponsors SET level = LEFT(c.name, 80), sponsor_category_id = NULL
                     FROM sponsor_categories c
                     WHERE sponsors.sponsor_category_id = c.id AND sponsors.event_id = c.event_id',
                );
            });
        });
    }
};
