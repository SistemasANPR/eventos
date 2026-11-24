<?php

declare(strict_types=1);

namespace App\Support\Privacy;

use Carbon\CarbonImmutable;

/** Resultado de `ExportPersonData`: el identificador, el enlace temporal firmado y los totales (sin contenido). */
final readonly class PersonDataExport
{
    /** @param  array<string, int>  $counts */
    public function __construct(
        public string $id,
        public string $url,
        public CarbonImmutable $expiresAt,
        public array $counts,
    ) {}
}
