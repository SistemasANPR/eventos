<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
 * Guardiana del plan 032 (F2, CA-032-7): el panel y las pantallas del autor no deciden a mano si un evento
 * o una convocatoria tienen página pública. Quien arma la URL de una página pública la pregunta a
 * `PublicPageAvailability`, la misma regla que usa el sitio público (`EnsureEventIsAvailable`).
 */
it('CA-032-7: code that builds a public page link asks PublicPageAvailability', function (): void {
    $root = dirname(__DIR__, 2).'/app';
    $routes = "/route\(\s*'(events\.show|events\.program|events\.privacy|calls\.show|calls\.submit\.show)'/";
    $offenders = [];

    $finder = (new Finder)->files()->name('*.php')
        ->in([$root.'/Filament', $root.'/Support/Landings', $root.'/Support/Submissions', $root.'/Http/Controllers/Author']);

    foreach ($finder as $file) {
        $source = $file->getContents();

        if (preg_match($routes, $source) === 1 && ! str_contains($source, 'PublicPageAvailability')) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([], 'Arman un enlace público sin PublicPageAvailability: '.implode(', ', $offenders));
});
