<?php

declare(strict_types=1);

namespace App\Support\Calls;

use App\Models\Track;

/**
 * Implementación por defecto de `TrackUsageChecker`: todavía no existen las
 * propuestas (plan 006), así que ningún eje está en uso. Se reemplaza por la
 * real, que además impone la clave foránea restrictiva.
 */
final class NoTrackUsage implements TrackUsageChecker
{
    public function isInUse(Track $track): bool
    {
        return false;
    }
}
