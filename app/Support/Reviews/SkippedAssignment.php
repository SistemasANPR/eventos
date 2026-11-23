<?php

declare(strict_types=1);

namespace App\Support\Reviews;

use App\Enums\ReviewAssignmentSkipReason;

/**
 * Una propuesta, o una pareja propuesta-evaluador, que `AssignReviewers` omitió y por qué (CA-011-40 y 41).
 * `reviewerId` es nulo cuando se omite la propuesta entera (invitada o en un estado no elegible).
 */
final readonly class SkippedAssignment
{
    public function __construct(
        public string $submissionId,
        public string $submissionTitle,
        public ?string $reviewerId,
        public ?string $reviewerName,
        public ReviewAssignmentSkipReason $reason,
    ) {}
}
