<?php

declare(strict_types=1);

/**
 * Guardiana del plan 023: las horas que ve una persona en el panel se formatean con `EventTime`,
 * que respeta el formato de hora del evento. Cualquier otro formato con hora en `app/Filament/` falla.
 *
 * Se permiten `Y-m-d H:i` y `Y-m-d H:i:s`: son el formato de almacenamiento de los campos y de la
 * trazabilidad en UTC, no el que lee el usuario. Un `date_format:` de validación no es una llamada a `format()`.
 */
const FILAMENT_DIRECTORY = __DIR__.'/../../app/Filament';

const EVENT_TIME_FORBIDDEN = '/\b(?:format|translatedFormat|isoFormat)\(\s*[\'"](?!Y-m-d H:i(?::s)?[\'"])[^\'"]*(?:[HhGg]:i|\bLTS?\b|\bL{3,4}\b)/';

/** @return list<string> rutas con una hora formateada a mano */
function handFormattedTimes(string $directory): array
{
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        if (preg_match(EVENT_TIME_FORBIDDEN, (string) file_get_contents($file->getPathname())) === 1) {
            $offenders[] = str_replace(dirname(__DIR__, 2).DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }

    return $offenders;
}

it('CA-023-11: no panel screen formats a time by hand instead of using EventTime', function (): void {
    expect(handFormattedTimes(FILAMENT_DIRECTORY))->toBe([]);
});

it('CA-023-11: the guard sees the panel files, so it cannot pass by looking at nothing', function (): void {
    $count = iterator_count(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(FILAMENT_DIRECTORY, FilesystemIterator::SKIP_DOTS)));

    expect($count)->toBeGreaterThan(20);
});

it('CA-023-11: the guard flags a hand-made time format and lets storage formats through', function (string $code, bool $flagged): void {
    expect(preg_match(EVENT_TIME_FORBIDDEN, $code) === 1)->toBe($flagged);
})->with([
    '24-hour display' => ["\$date->format('d/m/Y H:i')", true],
    '12-hour display' => ["\$date->translatedFormat('j M Y, g:i a')", true],
    'localized time' => ["\$date->locale('es')->isoFormat('LT')", true],
    'storage format' => ["\$date->format('Y-m-d H:i')", false],
    'storage format with seconds' => ["\$date->format('Y-m-d H:i:s')", false],
    'date only' => ["\$date->format('d/m/Y')", false],
]);
