<?php

declare(strict_types=1);

namespace App\Support\Calls;

use App\Models\Track;

/**
 * Pregunta si un eje temático tiene propuestas (plan 004, DA-4, CA-004-14). Un
 * eje en uso no se borra, solo se archiva. Las propuestas llegan con el plan 006,
 * que reemplaza la implementación nula por la real.
 */
interface TrackUsageChecker
{
    public function isInUse(Track $track): bool;
}
