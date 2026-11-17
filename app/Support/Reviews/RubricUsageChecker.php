<?php

declare(strict_types=1);

namespace App\Support\Reviews;

use App\Models\Rubric;

/**
 * Pregunta si una versión de rúbrica ya tiene evaluaciones registradas (plan
 * 011, DA-2, CA-011-3): si las tiene, no se edita, se crea una versión nueva.
 * Las evaluaciones llegan en la fase F4, que reemplaza la implementación nula
 * por la real (consulta de `reviews`).
 */
interface RubricUsageChecker
{
    public function hasRecordedReviews(Rubric $rubric): bool;
}
