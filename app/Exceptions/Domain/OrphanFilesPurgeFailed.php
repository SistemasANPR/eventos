<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use RuntimeException;

/**
 * La limpieza de archivos huérfanos no pudo borrar uno del disco (plan 007, H-007-3).
 * Es un fallo de infraestructura: se reporta y la tarea termina con error; nunca se
 * cuenta como «cero archivos borrados». No incluye el nombre del archivo.
 */
final class OrphanFilesPurgeFailed extends RuntimeException
{
    public const CODE = 'ORPHAN_FILES_PURGE_FAILED';

    public static function onDisk(string $disk, string $organizationId): self
    {
        return new self(self::CODE.": el disco '{$disk}' no borró un archivo huérfano de la organización {$organizationId}.");
    }
}
