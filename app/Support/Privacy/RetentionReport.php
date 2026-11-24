<?php

declare(strict_types=1);

namespace App\Support\Privacy;

use App\Enums\RetentionCategory;

/**
 * Lo que hizo `ApplyRetentionPolicy` en una organización: por categoría con plazo, cuántas filas o
 * propuestas anonimizó o eliminó. Solo totales: nunca datos personales (también va a la auditoría).
 */
final readonly class RetentionReport
{
    /**
     * @param  array<string, int>  $counts  por categoría (`RetentionCategory::value`) con plazo y que se aplica
     * @param  list<string>  $failed  categorías que fallaron (el fallo ya se reportó al gestor de errores)
     */
    public function __construct(
        public array $counts = [],
        public array $failed = [],
    ) {}

    /** ¿La organización tenía algún plazo que aplicar? Sin plazos no se hace nada ni se audita. */
    public function applied(): bool
    {
        return $this->counts !== [] || $this->failed !== [];
    }

    public function total(): int
    {
        return array_sum($this->counts);
    }

    public function hasFailures(): bool
    {
        return $this->failed !== [];
    }

    public function countOf(RetentionCategory $category): int
    {
        return $this->counts[$category->value] ?? 0;
    }

    /** @return array{counts: array<string, int>, failed: list<string>} */
    public function toArray(): array
    {
        return ['counts' => $this->counts, 'failed' => $this->failed];
    }
}
