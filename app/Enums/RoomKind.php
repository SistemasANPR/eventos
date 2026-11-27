<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tipo de un lugar del evento (plan 035, DA-1): `room` es una sala dentro del recinto, de uso exclusivo
 * (dos actividades no la comparten a la misma hora); `external` es un lugar fuera de él, como un parque
 * o un espacio público de la ciudad sede, y admite actividades simultáneas.
 */
enum RoomKind: string
{
    case Room = 'room';
    case External = 'external';

    /** Si dos actividades pueden ocupar el lugar a la vez (los externos sí; las salas no). */
    public function allowsOverlap(): bool
    {
        return $this === self::External;
    }
}
