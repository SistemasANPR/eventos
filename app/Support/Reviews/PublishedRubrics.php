<?php

declare(strict_types=1);

namespace App\Support\Reviews;

use App\Enums\RubricStatus;
use App\Models\Rubric;

/**
 * Rúbrica con que se evalúa hoy un tipo de propuesta (plan 011, DA-2): la versión PUBLICADA más reciente
 * del evento y del tipo, con sus criterios en orden. Una versión nueva publicada pasa a ser la vigente;
 * las evaluaciones ya hechas conservan la versión con que se hicieron. Exige la organización en contexto.
 */
final class PublishedRubrics
{
    public static function latestFor(string $eventId, string $submissionTypeId): ?Rubric
    {
        return Rubric::query()
            ->where('event_id', $eventId)
            ->where('submission_type_id', $submissionTypeId)
            ->where('status', RubricStatus::Published)
            ->orderByDesc('version')
            ->with('criteria')
            ->first();
    }
}
