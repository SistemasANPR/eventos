<?php

declare(strict_types=1);

namespace App\Support\Reviews;

/**
 * Un criterio en el que dos evaluadores de una propuesta difieren más que el `discrepancy_threshold` de su
 * rúbrica (plan 011, F6, CA-011-24): la diferencia mayor y la calificación de cada evaluador.
 */
final readonly class CriterionDiscrepancy
{
    /** @param  list<array{reviewer_id: string, reviewer_name: string, value: int}>  $scores */
    public function __construct(
        public string $criterionId,
        public string $label,
        public int $spread,
        public int $threshold,
        public array $scores,
    ) {}
}
