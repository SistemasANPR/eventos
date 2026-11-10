<?php

declare(strict_types=1);

namespace App\Support\Files;

use App\Models\CallForSubmissions;

/**
 * Implementación por defecto de `UploadTokenVerifier`: todavía no existe la
 * sesión del formulario público (plan 006), así que ningún token está emitido y
 * toda subida responde 403 (denegar por defecto). Se reemplaza cuando el plan
 * 006 emita los tokens.
 */
final class NoIssuedUploadTokens implements UploadTokenVerifier
{
    public function isIssued(CallForSubmissions $call, string $token): bool
    {
        return false;
    }
}
