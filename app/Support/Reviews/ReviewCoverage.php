<?php

declare(strict_types=1);

namespace App\Support\Reviews;

use App\Enums\RubricStatus;
use App\Models\Event;
use App\Models\Rubric;
use WeakMap;

/**
 * Cobertura de evaluadores de las propuestas de un evento (plan 011, CA-011-6): una propuesta con menos
 * asignaciones activas que el mínimo de su rúbrica queda con «cobertura incompleta». El mínimo sale de la
 * última versión PUBLICADA de la rúbrica del tipo de la propuesta; sin rúbrica publicada se usa
 * `DEFAULT_MIN_REVIEWERS`, el mismo valor por defecto de `rubrics.min_reviewers`.
 *
 * Se memoriza por instancia de evento: dura lo que dura la petición y no se queda vieja entre peticiones.
 */
final class ReviewCoverage
{
    public const DEFAULT_MIN_REVIEWERS = 2;

    /** @var WeakMap<Event, self>|null */
    private static ?WeakMap $instances = null;

    /** @var array<string, int>|null mínimo por tipo de propuesta */
    private ?array $minimums = null;

    private function __construct(private readonly Event $event) {}

    public static function for(Event $event): self
    {
        self::$instances ??= new WeakMap;

        return self::$instances[$event] ??= new self($event);
    }

    public function minimumFor(string $submissionTypeId): int
    {
        return $this->minimums()[$submissionTypeId] ?? self::DEFAULT_MIN_REVIEWERS;
    }

    public function isIncomplete(string $submissionTypeId, int $activeAssignments): bool
    {
        return $activeAssignments < $this->minimumFor($submissionTypeId);
    }

    /** @return array<string, int> */
    private function minimums(): array
    {
        if ($this->minimums !== null) {
            return $this->minimums;
        }

        $minimums = [];

        // De la versión más reciente a la más antigua: la primera de cada tipo es la vigente.
        $rubrics = Rubric::query()
            ->where('event_id', $this->event->id)
            ->where('status', RubricStatus::Published)
            ->orderByDesc('version')
            ->get(['submission_type_id', 'min_reviewers']);

        foreach ($rubrics as $rubric) {
            $minimums[$rubric->submission_type_id] ??= $rubric->min_reviewers;
        }

        return $this->minimums = $minimums;
    }
}
