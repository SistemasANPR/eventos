<?php

declare(strict_types=1);

/*
 * Guardiana de i18n (app/CLAUDE.md §10): `lang/es` y `lang/en` tienen los mismos
 * archivos y las mismas claves. Una clave que falta en un idioma se ve como la
 * clave cruda en la pantalla.
 */

/**
 * @param  array<string, mixed>  $array
 * @return list<string>
 */
function flattenKeys(array $array, string $prefix = ''): array
{
    $keys = [];

    foreach ($array as $key => $value) {
        $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
        $keys = is_array($value) ? [...$keys, ...flattenKeys($value, $path)] : [...$keys, $path];
    }

    sort($keys);

    return $keys;
}

/** @return list<string> */
function langFiles(string $locale): array
{
    $files = array_map('basename', glob(dirname(__DIR__, 2)."/lang/{$locale}/*.php") ?: []);
    sort($files);

    return $files;
}

it('has the same lang files in es and en', function (): void {
    expect(langFiles('es'))->toBe(langFiles('en'));
});

it('has the same keys in es and en for every lang file', function (string $file): void {
    $root = dirname(__DIR__, 2);

    $es = flattenKeys(require "{$root}/lang/es/{$file}");
    $en = flattenKeys(require "{$root}/lang/en/{$file}");

    // Las claves de `validation.custom` y `attributes` por campo pueden diferir en cantidad;
    // el resto de las claves debe coincidir.
    expect(array_values(array_diff($es, $en)))->toBe([], "Faltan en lang/en/{$file}")
        ->and(array_values(array_diff($en, $es)))->toBe([], "Faltan en lang/es/{$file}");
})->with(fn (): array => array_combine(langFiles('es'), array_map(static fn (string $f): array => [$f], langFiles('es'))));
