<?php

declare(strict_types=1);

namespace App\Support\Submissions;

/**
 * Resultado de revertir un lote de decisión masiva (plan 012, F2, CA-012-12 y 13): cuántas propuestas
 * volvieron a revisión, las que se omitieron con su motivo (nunca en silencio) y los avisos de lo que se
 * ocultó del programa al revertir aceptadas. Sin datos personales.
 */
final readonly class BatchReversalReport
{
    /** Motivo de omisión: la propuesta ya no está en el estado que le dejó el lote. */
    public const SKIP_STATUS_CHANGED = 'status_changed';

    /**
     * @param  list<array{reference: string, title: string, reason: string}>  $skipped
     * @param  list<string>  $programNotices
     */
    public function __construct(
        public int $reverted,
        public array $skipped,
        public array $programNotices,
    ) {}

    public function skippedCount(): int
    {
        return count($this->skipped);
    }
}
