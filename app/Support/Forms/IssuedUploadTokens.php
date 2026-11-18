<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Models\CallForSubmissions;

/** Almacén de los tokens de carga que el servidor emitió a una sesión de formulario (plan 006, DA-9). */
interface IssuedUploadTokens
{
    /** Emite un token nuevo para la convocatoria y registra solo su hash. */
    public function issue(CallForSubmissions $call): string;

    /** `true` si este token lo emitió la sesión actual para esta convocatoria. */
    public function has(CallForSubmissions $call, string $token): bool;
}
