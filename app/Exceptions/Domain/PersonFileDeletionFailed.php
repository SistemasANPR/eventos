<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use RuntimeException;

/**
 * El disco no pudo borrar un archivo de la persona (plan 014, DA-4). Se propaga: un archivo que sigue
 * en el disco es un dato personal que sobrevive, y degradarlo a un éxito lo ocultaría. Nada de la base
 * se ha cambiado todavía: la operación se puede repetir.
 */
final class PersonFileDeletionFailed extends RuntimeException
{
    public const CODE = 'PERSON_FILE_DELETION_FAILED';

    public static function onDisk(string $disk): self
    {
        return new self(self::CODE.": no se pudo borrar un archivo de la persona en el disco '{$disk}'.");
    }
}
