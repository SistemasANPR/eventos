<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
 * CA-026-12: los idiomas de un evento los decide `Event::offeredLocales()`. Una pantalla de Filament o un
 * Form Request que recorre la lista completa de idiomas del sistema (`StoreEventRequest::LOCALES`) vuelve
 * a pedir o aceptar texto en idiomas que el evento no ofrece. Solo se permite donde está en esta lista,
 * con su razón.
 */
it('CA-026-12: no screen or request walks the full list of system languages outside the allowlist', function (): void {
    $allowlist = [
        // La validación genérica de claves: el evento mismo aún no tiene idiomas con los que acotar.
        'app/Http/Requests/Events/StoreEventRequest.php',
        // Muestra el nombre y la descripción de un evento cuyos idiomas se están eligiendo en vivo en ese mismo formulario.
        'app/Filament/Resources/Events/Schemas/EventForm.php',
        // Pendiente (plan 026, fuera de F2): título y descripción de la convocatoria se acotan por `call.locales`, que ya se valida contra el evento.
        'app/Http/Requests/Calls/StoreCallForSubmissionsRequest.php',
    ];

    $root = dirname(__DIR__, 2);
    $offenders = [];
    $finder = (new Finder)->files()->in([$root.'/app/Filament', $root.'/app/Http/Requests'])->name('*.php');

    foreach ($finder as $file) {
        $path = 'app/'.str_replace('\\', '/', substr($file->getPathname(), strlen($root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR)));

        if (! in_array($path, $allowlist, true) && preg_match('/\b(?:StoreEventRequest|self|static)::LOCALES\b/', $file->getContents()) === 1) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([], 'Recorren la lista completa de idiomas; usa Event::offeredLocales() o LocalizedFields: '.implode(', ', $offenders));
});

it('CA-026-12: the allowlist has no stale entries', function (): void {
    $root = dirname(__DIR__, 2);

    foreach ([
        'app/Http/Requests/Events/StoreEventRequest.php',
        'app/Filament/Resources/Events/Schemas/EventForm.php',
        'app/Http/Requests/Calls/StoreCallForSubmissionsRequest.php',
    ] as $path) {
        expect(file_get_contents($root.'/'.$path))->toMatch('/::LOCALES\b/');
    }
});
