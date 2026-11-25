<?php

declare(strict_types=1);

namespace App\Support\Reviews;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Detalle de avance de un evaluador (plan 011, F6, CA-011-58): su avance y sus asignaciones paginadas. */
final readonly class ReviewerProgressDetailReport
{
    /** @param  LengthAwarePaginator<int, ReviewerProgressAssignment>  $assignments */
    public function __construct(
        public string $reviewerId,
        public string $name,
        public string $email,
        public ReviewerProgress $progress,
        public LengthAwarePaginator $assignments,
    ) {}
}
