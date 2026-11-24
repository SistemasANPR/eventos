<?php

declare(strict_types=1);

namespace App\Support\Submissions;

use App\Enums\SubmissionStatus;

/**
 * Resultado de una decisión masiva (plan 012, F2): el lote creado, cuántas propuestas cambiaron de estado
 * (por estado de partida) y cuántos resultados se avisaron de inmediato porque la convocatoria ya había
 * publicado los suyos (DA-2). Sin datos personales.
 */
final readonly class BulkDecisionReport
{
    /**
     * @param  array<string, int>  $byFromStatus  propuestas por estado en que estaban antes de decidir
     */
    public function __construct(
        public string $batchId,
        public SubmissionStatus $to,
        public int $total,
        public array $byFromStatus,
        public int $publishedNow,
    ) {}
}
