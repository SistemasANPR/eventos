<?php

declare(strict_types=1);

namespace App\Enums;

/** Qué pasó al quitar una asignación de evaluador (plan 011, DA-7): se eliminó o se canceló conservando su evaluación. */
enum ReviewAssignmentRemoval: string
{
    case Deleted = 'deleted';
    case Cancelled = 'cancelled';
}
