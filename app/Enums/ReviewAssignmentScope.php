<?php

declare(strict_types=1);

namespace App\Enums;

/** Alcance de una operación de asignación (plan 011, DA-6): propuestas elegidas, un eje o una convocatoria. */
enum ReviewAssignmentScope: string
{
    case Submission = 'submission';
    case Track = 'track';
    case Call = 'call';
}
