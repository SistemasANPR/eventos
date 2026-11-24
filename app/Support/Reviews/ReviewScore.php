<?php

declare(strict_types=1);

namespace App\Support\Reviews;

/**
 * La evaluación vigente de un evaluador sobre una propuesta (plan 011, F6): su promedio ponderado (en la escala de
 * la versión de rúbrica con que evaluó), su porcentaje normalizado y lo que respondió en cada criterio.
 */
final readonly class ReviewScore
{
    /**
     * @param  list<array{criterion_id: string, label: string, value: int}>  $scores  calificaciones por criterio
     * @param  list<array{label: string, text: string}>  $comments  comentarios de los criterios de texto
     */
    public function __construct(
        public string $reviewerId,
        public string $reviewerName,
        public int $rubricVersion,
        public float $average,
        public float $percentage,
        public array $scores,
        public array $comments,
    ) {}
}
