<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Rol de un usuario dentro de un evento (plan 011, H-011-7). `speaker` llega en R2;
 * por ahora no existe y se rechaza.
 */
enum EventAssignmentRole: string
{
    case Reviewer = 'reviewer';
    case Coordinator = 'coordinator';
}
