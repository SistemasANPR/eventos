<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * La subida no trae un `upload_token` o no es uno que la sesión del formulario
 * haya emitido (plan 007, CA-007-10). Responde 403 y no dice cuál de los dos casos fue.
 */
final class UploadTokenInvalid extends AccessDeniedHttpException
{
    public const CODE = 'UPLOAD_TOKEN_INVALID';

    public static function make(): self
    {
        return new self(__('files.upload_token_invalid'));
    }
}
