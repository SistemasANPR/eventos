<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
 * CA-004-23: un controlador público nunca se salta el filtro de organización.
 * La organización llega por `SetOrganizationFromRoute` y todo se acota con ella.
 */
it('CA-004-23: public controllers never call withoutOrganizationScope()', function (): void {
    $offenders = [];
    $finder = (new Finder)->files()->in(dirname(__DIR__, 2).'/app/Http/Controllers/Public')->name('*.php');

    foreach ($finder as $file) {
        if (str_contains($file->getContents(), 'withoutOrganizationScope')) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([], 'Controladores públicos con withoutOrganizationScope: '.implode(', ', $offenders));
});
