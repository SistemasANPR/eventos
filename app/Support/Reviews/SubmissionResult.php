<?php

declare(strict_types=1);

namespace App\Support\Reviews;

use App\Enums\SubmissionStatus;

/**
 * Resultado de una propuesta (plan 011, F6, H-011-5), tal como lo devuelve `ComputeSubmissionResults`.
 *
 * - `average`: promedio de los promedios ponderados de cada evaluación, en las unidades de la escala; `deviation`:
 *   desviación estándar poblacional de esos promedios (0 con una sola evaluación). Son nulos sin evaluaciones.
 * - `percentage`: el mismo promedio pero normalizado a 0–100 por la escala de cada criterio; es lo que ordena el
 *   ranking, porque criterios con escalas distintas no se comparan en bruto.
 * - `incomplete`: menos evaluaciones vigentes que el mínimo de la rúbrica; una incompleta no recibe `rank`.
 * - `rank`: posición entre las completas (empates comparten posición); nulo si está incompleta.
 */
final readonly class SubmissionResult
{
    /**
     * @param  list<ReviewScore>  $reviews  una por evaluador, con calificaciones y comentarios
     * @param  list<CriterionDiscrepancy>  $discrepancies
     */
    public function __construct(
        public string $submissionId,
        public string $title,
        public SubmissionStatus $status,
        public ?string $trackId,
        public ?string $callId,
        public int $evaluations,
        public int $minimum,
        public bool $incomplete,
        public ?float $average,
        public ?float $percentage,
        public ?float $deviation,
        public array $reviews,
        public array $discrepancies,
        public ?int $rank = null,
    ) {}

    public function hasDiscrepancies(): bool
    {
        return $this->discrepancies !== [];
    }

    public function withRank(?int $rank): self
    {
        return new self(
            $this->submissionId, $this->title, $this->status, $this->trackId, $this->callId, $this->evaluations,
            $this->minimum, $this->incomplete, $this->average, $this->percentage, $this->deviation,
            $this->reviews, $this->discrepancies, $rank,
        );
    }
}
