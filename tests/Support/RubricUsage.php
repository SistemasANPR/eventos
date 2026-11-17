<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Rubric;
use App\Support\Reviews\RubricUsageChecker;

/**
 * Doble de `RubricUsageChecker` (plan 011, DA-2): F4 trae la implementación
 * real, que consulta las evaluaciones. Aquí se marca a mano qué rúbricas ya
 * tienen evaluaciones registradas.
 */
final class RubricUsage implements RubricUsageChecker
{
    /** @param  list<string>  $withReviews  identificadores de las rúbricas con evaluaciones */
    public function __construct(private readonly array $withReviews = []) {}

    public static function install(Rubric ...$rubrics): void
    {
        app()->instance(RubricUsageChecker::class, new self(array_map(static fn (Rubric $rubric): string => $rubric->id, $rubrics)));
    }

    public function hasRecordedReviews(Rubric $rubric): bool
    {
        return in_array($rubric->id, $this->withReviews, true);
    }
}
