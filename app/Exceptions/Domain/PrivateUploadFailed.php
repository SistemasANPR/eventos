<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use RuntimeException;

/**
 * El disco privado no pudo guardar el archivo (plan 007). Es un fallo de
 * infraestructura, no de validación: se propaga y no se degrada a un éxito vacío.
 */
final class PrivateUploadFailed extends RuntimeException
{
    public const CODE = 'PRIVATE_UPLOAD_FAILED';

    public static function onDisk(string $disk): self
    {
        return new self(self::CODE.": no se pudo guardar el archivo en el disco '{$disk}'.");
    }
}
