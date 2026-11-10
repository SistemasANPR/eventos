<?php

declare(strict_types=1);

namespace App\Support\Files;

use App\Models\CallForSubmissions;

/**
 * Implementación por defecto de `FileFieldRegistry`: todavía no existe el
 * constructor de formularios (plan 005), así que ninguna convocatoria tiene
 * campos de archivo y toda subida se rechaza (denegar por defecto). Se
 * reemplaza por la implementación real cuando exista el formulario.
 */
final class NoFileFields implements FileFieldRegistry
{
    public function exists(CallForSubmissions $call, string $fieldKey): bool
    {
        return false;
    }
}
