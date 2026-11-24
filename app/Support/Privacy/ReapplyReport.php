<?php

declare(strict_types=1);

namespace App\Support\Privacy;

/**
 * Lo que hizo `ReapplyAnonymizations` en una organización. Solo totales y códigos, sin datos personales:
 * es lo que el comando imprime y lo que se audita.
 */
final readonly class ReapplyReport
{
    /**
     * @param  int  $registered  personas en `anonymization_log` de la organización
     * @param  int  $found  de ellas, las que vuelven a aparecer con datos en lo restaurado
     * @param  int  $reapplied  las que se volvieron a anonimizar (o, con simulación, las que se anonimizarían)
     * @param  array<string, int>  $skipped  omitidas por motivo (`last_administrator`, `platform_account`)
     * @param  int  $failed  las que fallaron (un archivo que el disco no pudo borrar)
     */
    public function __construct(
        public int $registered = 0,
        public int $found = 0,
        public int $reapplied = 0,
        public array $skipped = [],
        public int $failed = 0,
    ) {}

    public function skippedTotal(): int
    {
        return array_sum($this->skipped);
    }

    /** Hay personas registradas que siguen con sus datos: el comando debe terminar con error para que se vea. */
    public function isIncomplete(): bool
    {
        return $this->failed > 0 || $this->skippedTotal() > 0;
    }

    /** @return array{registered: int, found: int, reapplied: int, skipped: array<string, int>, failed: int} */
    public function toArray(): array
    {
        return [
            'registered' => $this->registered,
            'found' => $this->found,
            'reapplied' => $this->reapplied,
            'skipped' => $this->skipped,
            'failed' => $this->failed,
        ];
    }
}
