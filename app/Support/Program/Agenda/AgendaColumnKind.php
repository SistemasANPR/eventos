<?php

declare(strict_types=1);

namespace App\Support\Program\Agenda;

/**
 * Qué representa una columna de la agenda de lectura (plan 031, DA-7; plan 035, DA-9): una sala del recinto (una
 * columna por sala, en el orden de `position`), todos los lugares externos juntos en una sola columna «Fuera de sede»
 * que admite actividades simultáneas, o las actividades sin lugar.
 */
enum AgendaColumnKind: string
{
    case Room = 'room';
    case External = 'external';
    case None = 'none';
}
