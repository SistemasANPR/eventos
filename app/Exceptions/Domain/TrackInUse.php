<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use DomainException;

/** Un eje con propuestas no se elimina: solo se archiva (plan 004, DA-4, CA-004-14). */
final class TrackInUse extends DomainException
{
    public const CODE = 'TRACK_IN_USE';

    public function __construct()
    {
        parent::__construct('El eje tiene propuestas y no se puede eliminar; solo archivar (código '.self::CODE.').');
    }
}
