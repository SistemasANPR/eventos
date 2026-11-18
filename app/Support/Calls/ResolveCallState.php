<?php

declare(strict_types=1);

namespace App\Support\Calls;

use App\Enums\CallState;
use App\Enums\CallStatus;
use App\Models\CallForSubmissions;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * Estado público de una convocatoria (plan 004, DA-1): se CALCULA en cada
 * petición con el instante del servidor, sin depender de un comando programado
 * ni de un *worker*. Una convocatoria abre y cierra a su hora aunque algo falle.
 *
 * Con `status = published`: `scheduled` antes de `opens_at`, `open` desde
 * `opens_at` (incluido) y `closed` desde `closes_at` (el cierre es EXCLUSIVO).
 * Un borrador no tiene estado público (`null`).
 */
final class ResolveCallState
{
    public static function for(CallForSubmissions $call, ?CarbonInterface $now = null): ?CallState
    {
        $now ??= Date::now();

        return match ($call->status) {
            CallStatus::Draft => null,
            CallStatus::Paused => CallState::Paused,
            CallStatus::Closed => CallState::Closed,
            CallStatus::Published => match (true) {
                $now->lt($call->opens_at) => CallState::Scheduled,
                $now->lt($call->closes_at) => CallState::Open,
                default => CallState::Closed,
            },
        };
    }
}
