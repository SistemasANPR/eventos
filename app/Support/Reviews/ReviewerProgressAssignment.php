<?php

declare(strict_types=1);

namespace App\Support\Reviews;

use App\Enums\ReviewAssignmentStatus;
use Carbon\CarbonInterface;

/**
 * Una asignación en el detalle de avance de un evaluador (plan 011, F6, CA-011-58): la propuesta, el estado de la
 * asignación y la fecha de su última actividad. Por diseño no lleva calificaciones ni comentarios.
 */
final readonly class ReviewerProgressAssignment
{
    public function __construct(
        public string $submissionId,
        public string $title,
        public ReviewAssignmentStatus $status,
        public ?CarbonInterface $lastActivityAt,
    ) {}
}
