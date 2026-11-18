<?php

declare(strict_types=1);

namespace App\Support\Reviews;

use App\Models\Rubric;

/**
 * Implementación por defecto de `RubricUsageChecker`: la tabla `reviews` aún no
 * existe (plan 011, F4), así que ninguna rúbrica tiene evaluaciones. F4 la
 * sustituye por la real.
 */
final class NoRecordedReviews implements RubricUsageChecker
{
    public function hasRecordedReviews(Rubric $rubric): bool
    {
        return false;
    }
}
