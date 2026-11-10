<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Track;
use App\Support\Calls\TrackUsageChecker;

/**
 * Doble de `TrackUsageChecker` (plan 004, DA-4): el plan 006 trae la
 * implementación real, que consulta las propuestas. Aquí se marca a mano qué
 * ejes están en uso.
 */
final class TrackUsage implements TrackUsageChecker
{
    /** @param  list<string>  $inUse  identificadores de los ejes en uso */
    public function __construct(private readonly array $inUse = []) {}

    /** Sustituye la implementación nula por este doble con los ejes indicados en uso. */
    public static function install(Track ...$tracks): void
    {
        app()->instance(TrackUsageChecker::class, new self(array_map(static fn (Track $track): string => $track->id, $tracks)));
    }

    public function isInUse(Track $track): bool
    {
        return in_array($track->id, $this->inUse, true);
    }
}
