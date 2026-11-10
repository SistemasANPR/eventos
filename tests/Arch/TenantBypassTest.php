<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
 * Guardiana: atajos que se saltan el scope de organización o los eventos de
 * `BelongsToOrganization` (asignación automática y rechazo de otra
 * organización). Solo `app/Support/Tenancy` puede usarlos. Un uso legítimo
 * (soporte, agregados de plataforma) pasa por `withoutOrganizationScope()`
 * con autorización y auditoría (ADR-0003, acción 10 de AGENTS.md); si hace
 * falta otra excepción, se decide y se registra aquí.
 */

const TENANT_BYPASS_PATTERNS = [
    'withoutGlobalScope' => '/->\s*withoutGlobalScopes?\s*\(|::\s*withoutGlobalScopes?\s*\(/',
    'saveQuietly' => '/->\s*saveQuietly\s*\(/',
    'withoutEvents' => '/::\s*withoutEvents\s*\(/',
    'DB::table' => '/\bDB::table\s*\(/',
    'newQueryWithoutScopes' => '/->\s*newQueryWithoutScopes?\s*\(/',
    'newModelQuery' => '/->\s*newModelQuery\s*\(/',
    'DB:: SQL crudo' => '/\bDB::(select|selectOne|statement|unprepared|insert|update|delete)\s*\(/',
    'insert/upsert masivo' => '/(::|->)\s*(insert|insertOrIgnore|insertGetId|upsert)\s*\(/',
];

it('does not use shortcuts that bypass the organization scope in app/', function (string $label, string $pattern): void {
    $offenders = [];
    $finder = (new Finder)->files()->in(dirname(__DIR__, 2).'/app')->name('*.php')->notPath('Support/Tenancy');

    foreach ($finder as $file) {
        if (preg_match($pattern, $file->getContents()) === 1) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([], "{$label} se salta el aislamiento entre organizaciones en: ".implode(', ', $offenders));
})->with(array_map(
    static fn (string $label, string $pattern): array => [$label, $pattern],
    array_keys(TENANT_BYPASS_PATTERNS),
    array_values(TENANT_BYPASS_PATTERNS),
));
