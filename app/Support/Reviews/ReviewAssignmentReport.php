<?php

declare(strict_types=1);

namespace App\Support\Reviews;

use App\Enums\ReviewAssignmentSkipReason;

/**
 * Informe de una operación de `AssignReviewers` (plan 011, CA-011-41): lo asignado, lo omitido con su
 * motivo y los evaluadores a quienes no se pudo encolar el aviso. Una operación nunca omite en silencio.
 */
final readonly class ReviewAssignmentReport
{
    /**
     * @param  string  $operationId  identifica la operación (es el hecho de negocio del correo resumen)
     * @param  list<array{submission_id: string, reviewer_id: string}>  $assigned  asignaciones nuevas
     * @param  list<SkippedAssignment>  $skipped
     * @param  list<string>  $notificationFailures  ids de evaluadores cuyo aviso no se pudo encolar; su asignación sí persiste
     */
    public function __construct(
        public string $operationId,
        public array $assigned,
        public array $skipped,
        public array $notificationFailures = [],
    ) {}

    public function assignedCount(): int
    {
        return count($this->assigned);
    }

    public function skippedCount(): int
    {
        return count($this->skipped);
    }

    /** @return array<string, int> omitidas por motivo (clave = valor del motivo) */
    public function skippedByReason(): array
    {
        $counts = [];

        foreach ($this->skipped as $item) {
            $counts[$item->reason->value] = ($counts[$item->reason->value] ?? 0) + 1;
        }

        return $counts;
    }

    /** @return list<SkippedAssignment> */
    public function skippedFor(ReviewAssignmentSkipReason $reason): array
    {
        return array_values(array_filter($this->skipped, static fn (SkippedAssignment $item): bool => $item->reason === $reason));
    }

    /** @param  list<string>  $reviewerIds */
    public function withNotificationFailures(array $reviewerIds): self
    {
        return new self($this->operationId, $this->assigned, $this->skipped, $reviewerIds);
    }
}
