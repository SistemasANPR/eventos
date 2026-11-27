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

it('CA-032-18: the texts of the unavailable event page and of the panel explanations exist in es and en, non-empty and different', function (): void {
    $root = dirname(__DIR__, 2);
    $keys = [
        'public.php' => ['unavailable.title', 'unavailable.body', 'unavailable.hint', 'unavailable.submit_error'],
        'calls.php' => ['actions.public_unavailable.archived', 'actions.public_unavailable.draft_event', 'actions.event_archived_badge'],
    ];

    foreach ($keys as $file => $paths) {
        foreach ($paths as $path) {
            $es = data_get(require "{$root}/lang/es/{$file}", $path);
            $en = data_get(require "{$root}/lang/en/{$file}", $path);

            expect($es)->toBeString()
                ->and($en)->toBeString()
                ->and($es !== '' && $en !== '' && $es !== $en)->toBeTrue("lang/es y lang/en de {$file}: {$path} vacío o sin traducir");
        }
    }
});

it('CA-034-18: the texts of the author access (plan 034) exist in es and en and are not empty', function (string $key): void {
    foreach (['es', 'en'] as $locale) {
        $text = trans("public.{$key}", [], $locale);

        expect($text)->toBeString()
            ->and($text)->not->toBe("public.{$key}")
            ->and($text)->not->toBe('');
    }
})->with([
    'call.already_submitted',
    'submitted.edit_action',
    'submitted.edit_until',
    'author.show.edit_until',
    'author.show.edit_closed',
    'author.show.edit_closed_no_date',
    'author.show.edit_in_process',
    'author.show.logout',
    'author.show.logging_out',
    'author.request.intro',
]);
