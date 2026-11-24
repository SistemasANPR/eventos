<?php

declare(strict_types=1);

namespace App\Support\Reviews;

use App\Models\Review;
use App\Models\Rubric;

/**
 * Implementación real de `RubricUsageChecker` (plan 011, DA-2, CA-011-3): una versión de rúbrica ya
 * tiene evaluaciones registradas en cuanto alguna evaluación (incluso un borrador, o una eliminada
 * lógicamente) se hizo con ella. Reemplaza a `NoRecordedReviews`, que valía mientras no existía `reviews`.
 */
final class RecordedReviews implements RubricUsageChecker
{
    public function hasRecordedReviews(Rubric $rubric): bool
    {
        return Review::query()->withTrashed()->where('rubric_id', $rubric->id)->exists();
    }
}
