<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
 * Controladores del acceso del autor (plan 006): nunca se saltan el filtro de organización (CA-006-18) y
 * nunca registran nada (CA-006-39: el token de la URL no debe llegar a los registros de la aplicación).
 */
it('CA-006-18: author controllers never call withoutOrganizationScope()', function (): void {
    $offenders = [];
    $finder = (new Finder)->files()->in(dirname(__DIR__, 2).'/app/Http/Controllers/Author')->name('*.php');

    foreach ($finder as $file) {
        if (str_contains($file->getContents(), 'withoutOrganizationScope')) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([], 'Controladores del autor con withoutOrganizationScope: '.implode(', ', $offenders));
});

it('CA-006-39: author controllers and the access-token actions never write to the logs', function (): void {
    $offenders = [];
    $files = [
        ...iterator_to_array((new Finder)->files()->in(dirname(__DIR__, 2).'/app/Http/Controllers/Author')->name('*.php'), false),
        ...iterator_to_array((new Finder)->files()->in(dirname(__DIR__, 2).'/app/Actions/Submissions')->name(['ConsumeAccessToken.php', 'CreateAccessToken.php', 'SendAccessLink.php']), false),
    ];

    foreach ($files as $file) {
        if (preg_match('/\bLog::|\blogger\(|\bReport::|\breport\(/', $file->getContents()) === 1) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([], 'Escriben en los registros: '.implode(', ', $offenders));
});
