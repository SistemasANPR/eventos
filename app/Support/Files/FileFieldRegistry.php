<?php

declare(strict_types=1);

namespace App\Support\Files;

use App\Models\CallForSubmissions;

/**
 * Pregunta al formulario de la convocatoria si una clave es un campo de archivo
 * suyo (plan 007, CA-007-6). El formulario lo define el constructor de
 * formularios (plan 005), que reemplazará la implementación por defecto.
 */
interface FileFieldRegistry
{
    public function exists(CallForSubmissions $call, string $fieldKey): bool;
}
