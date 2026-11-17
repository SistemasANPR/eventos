<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Event;
use App\Models\SubmissionFile;
use Illuminate\Support\Arr;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Tests\Support\TenantModels;

/*
 * Guardiana del panel de plataforma (plan 016, CA-016-31). El panel muestra
 * metadatos, no el contenido de las organizaciones: ningún archivo de
 * `app/Filament/Platform` consulta un modelo con organización salvo `Event`, y
 * `Event` solo con `withoutOrganizationScope()` (que audita). Ni los contactos ni
 * los archivos de propuestas se referencian desde aquí.
 */

/** @return list<SplFileInfo> */
function platformPanelFiles(): array
{
    $directory = dirname(__DIR__, 2).'/app/Filament/Platform';

    return is_dir($directory) ? iterator_to_array((new Finder)->files()->in($directory)->name('*.php'), false) : [];
}

it('CA-016-31: the platform panel only reads Event among the organization models, always skipping the filter with the audited method', function (): void {
    $offenders = [];

    foreach (platformPanelFiles() as $file) {
        $source = $file->getContents();

        foreach (TenantModels::scoped() as $model) {
            $short = class_basename($model);

            if (preg_match('/\b'.preg_quote($short, '/').'::|use '.preg_quote($model, '/').';/', $source) !== 1) {
                continue;
            }

            $isEventWithBypass = $model === Event::class && str_contains($source, 'withoutOrganizationScope()');

            if (! $isEventWithBypass) {
                $offenders[] = "{$file->getRelativePathname()} ({$short})";
            }
        }
    }

    expect($offenders)->toBe([], 'El panel de plataforma consulta un modelo de organización sin el salto auditado o fuera de lo permitido: '.implode(', ', $offenders));
});

it('CA-016-31: the platform panel never references contacts or proposal files', function (): void {
    $offenders = [];

    foreach (platformPanelFiles() as $file) {
        foreach ([Contact::class, SubmissionFile::class] as $forbidden) {
            if (str_contains($file->getContents(), class_basename($forbidden))) {
                $offenders[] = $file->getRelativePathname().' ('.class_basename($forbidden).')';
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('CA-016-31: the guard sees the panel files, so it cannot pass by looking at nothing', function (): void {
    expect(count(platformPanelFiles()))->toBeGreaterThan(5);
});

it('CA-016-32: the platform panel texts exist in Spanish and English with the same keys', function (): void {
    $es = array_keys(Arr::dot(require dirname(__DIR__, 2).'/lang/es/platform.php'));
    $en = array_keys(Arr::dot(require dirname(__DIR__, 2).'/lang/en/platform.php'));
    sort($es);
    sort($en);

    expect($es)->toBe($en)->and($es)->not->toBeEmpty();
});
