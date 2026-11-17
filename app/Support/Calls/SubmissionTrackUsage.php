<?php

declare(strict_types=1);

namespace App\Support\Calls;

use App\Models\Submission;
use App\Models\Track;

/** Implementación real de `TrackUsageChecker` (plan 006, CA-006-17): un eje con propuestas está en uso. */
final class SubmissionTrackUsage implements TrackUsageChecker
{
    public function isInUse(Track $track): bool
    {
        return Submission::query()->where('track_id', $track->id)->exists();
    }
}
