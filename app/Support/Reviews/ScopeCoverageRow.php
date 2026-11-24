<?php

declare(strict_types=1);

namespace App\Support\Reviews;

use App\Enums\ReviewAssignmentScope;

/** Un eje o convocatoria con cobertura incompleta (CA-011-48): cuántas de sus propuestas por evaluar no llegan al mínimo. */
final readonly class ScopeCoverageRow
{
    public function __construct(
        public ReviewAssignmentScope $scope,
        public string $id,
        public string $name,
        public int $incompleteSubmissions,
        public int $totalSubmissions,
    ) {}
}
