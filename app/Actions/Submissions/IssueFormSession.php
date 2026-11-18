<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Models\CallForSubmissions;
use App\Support\Forms\IdempotencyKeys;
use App\Support\Forms\IssuedUploadTokens;

/**
 * Abre la «sesión del formulario» público al renderizarlo (plan 006, DA-9): emite una clave
 * de idempotencia y un token de carga, y guarda en la sesión solo su hash. Cada renderizado
 * emite valores nuevos.
 */
final class IssueFormSession
{
    public function __construct(
        private readonly IdempotencyKeys $keys,
        private readonly IssuedUploadTokens $uploadTokens,
    ) {}

    /** @return array{idempotency_key: string, upload_token: string} */
    public function handle(CallForSubmissions $call): array
    {
        return [
            'idempotency_key' => $this->keys->issue($call),
            'upload_token' => $this->uploadTokens->issue($call),
        ];
    }
}
