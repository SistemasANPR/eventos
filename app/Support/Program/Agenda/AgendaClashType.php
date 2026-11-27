<?php

declare(strict_types=1);

namespace App\Support\Program\Agenda;

/** Qué choca (plan 031, DA-5): `room` es una sala ocupada por dos actividades a la vez; `speaker`, un ponente en dos actividades solapadas. */
enum AgendaClashType: string
{
    case Room = 'room';
    case Speaker = 'speaker';
}
