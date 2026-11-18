<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Lang;

/*
 * Guardiana de `lang/{es,en}/program.php`: la integración de F2 y F3 dejó el bloque de actividades
 * anidado dentro de `speakers.columns`, así que `program.activities.*` y `program.speakers.fields.*`
 * no existían y `__()` devolvía la propia clave sin que ninguna prueba lo notara.
 */
it('CA-021-19: the program labels resolve at their own path in every language', function (string $locale, string $key): void {
    expect(Lang::has($key, $locale, false))->toBeTrue("Falta {$key} en {$locale}");
})->with(fn (): array => collect(['es', 'en'])->flatMap(fn (string $locale): array => collect([
    'program.speakers.navigation',
    'program.speakers.columns.photo',
    'program.speakers.fields.first_name',
    'program.speakers.fields.activities',
    'program.speakers.errors.activity_invalid',
    'program.activities.navigation',
    'program.activities.columns.speakers',
    'program.activities.form.speakers',
    'program.activities.speaker_invalid',
    'program.activities.actions.create',
])->mapWithKeys(fn (string $key): array => ["{$locale} {$key}" => [$locale, $key]])->all())->all());
