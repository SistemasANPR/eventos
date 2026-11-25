<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Cómo nació una asignación (plan 011, §4): `manual` la hizo una persona (individual, en lote o con una
 * expansión única por eje o convocatoria); `scope` la hizo una regla de alcance guardada (F3b).
 */
enum ReviewAssignmentSource: string
{
    case Manual = 'manual';
    case Scope = 'scope';
}
