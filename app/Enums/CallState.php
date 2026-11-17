<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado que ve el público, calculado en cada petición a partir de `CallStatus`
 * y de las fechas (plan 004, DA-1). No depende de ningún comando programado.
 */
enum CallState: string
{
    case Scheduled = 'scheduled';
    case Open = 'open';
    case Paused = 'paused';
    case Closed = 'closed';
}
