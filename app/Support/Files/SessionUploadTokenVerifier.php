<?php

declare(strict_types=1);

namespace App\Support\Files;

use App\Models\CallForSubmissions;
use App\Support\Forms\IssuedUploadTokens;

/** Verificador real (plan 006, DA-9): el token debe haberlo emitido la sesión del formulario para esta convocatoria. */
final class SessionUploadTokenVerifier implements UploadTokenVerifier
{
    public function __construct(private readonly IssuedUploadTokens $issued) {}

    public function isIssued(CallForSubmissions $call, string $token): bool
    {
        return $this->issued->has($call, $token);
    }
}
