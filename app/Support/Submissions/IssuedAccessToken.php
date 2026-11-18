<?php

declare(strict_types=1);

namespace App\Support\Submissions;

use Illuminate\Support\Carbon;
use SensitiveParameter;

/**
 * Resultado de `CreateAccessToken`: el valor en claro solo existe aquí, en memoria, para armar el
 * enlace del correo. En la base solo queda su hash (CA-006-33). No se serializa ni se registra.
 */
final readonly class IssuedAccessToken
{
    public function __construct(
        public string $id,
        #[SensitiveParameter] public string $plain,
        public Carbon $expiresAt,
    ) {}
}
