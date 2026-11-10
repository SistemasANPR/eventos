<?php

declare(strict_types=1);

namespace App\Support\Files;

use App\Models\CallForSubmissions;

/**
 * Pregunta a la sesión del formulario público si el `upload_token` lo emitió
 * ella para esta convocatoria (plan 007 §4, CA-007-10). El token lo emite el
 * plan 006 al renderizar el formulario; este plan solo guarda su hash.
 */
interface UploadTokenVerifier
{
    public function isIssued(CallForSubmissions $call, string $token): bool;
}
