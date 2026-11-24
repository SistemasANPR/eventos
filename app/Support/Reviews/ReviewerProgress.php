<?php

declare(strict_types=1);

namespace App\Support\Reviews;

use App\Enums\ReviewAssignmentStatus;
use App\Models\Event;
use App\Models\ReviewAssignment;
use Illuminate\Database\Eloquent\Builder;

/**
 * Avance de un evaluador en un evento (plan 011, F6, CA-011-25, 55 y 56). Es el ÚNICO lugar donde se calcula
 * el porcentaje: lo usan la vista «por evaluador» de la pantalla de asignación (F3b) y la página de avance.
 *
 * - Cuentan para el avance las asignaciones `pending`, `in_progress` y `submitted`; el porcentaje es
 *   `submitted` entre ese total.
 * - Las `cancelled` quedan fuera del numerador y del denominador (y no se guardan aquí).
 * - Las `declined_conflict` se cuentan aparte: ni suman ni restan al porcentaje.
 * - Sin asignaciones activas no hay porcentaje (`percentage()` es nulo y la etiqueta dice «sin asignaciones»),
 *   no un 0 %.
 */
final readonly class ReviewerProgress
{
    public function __construct(
        public int $pending = 0,
        public int $inProgress = 0,
        public int $submitted = 0,
        public int $declinedConflict = 0,
    ) {}

    /** Asignaciones que entran en el avance (el denominador). */
    public function active(): int
    {
        return $this->pending + $this->inProgress + $this->submitted;
    }

    public function hasAssignments(): bool
    {
        return $this->active() > 0;
    }

    /** Porcentaje entero de evaluaciones enviadas (redondeado), o nulo si no hay asignaciones activas. */
    public function percentage(): ?int
    {
        return $this->hasAssignments() ? (int) round($this->submitted * 100 / $this->active()) : null;
    }

    /** Si el avance está por debajo del umbral (en porcentaje). Sin asignaciones no hay nada que resaltar. */
    public function isBelow(int $thresholdPercentage): bool
    {
        return $this->hasAssignments() && $this->submitted * 100 < $thresholdPercentage * $this->active();
    }

    /** «80 % · 8 de 10», o «Sin propuestas asignadas». */
    public function label(): string
    {
        $percentage = $this->percentage();

        if ($percentage === null) {
            return __('review_assignments.reviewers.no_assignments');
        }

        return __('review_assignments.reviewers.progress', ['percent' => $percentage, 'done' => $this->submitted, 'total' => $this->active()]);
    }

    /**
     * Suma de varios avances (el avance global del evento).
     *
     * @param  iterable<self>  $progresses
     */
    public static function sum(iterable $progresses): self
    {
        $total = new self;

        foreach ($progresses as $progress) {
            $total = new self(
                $total->pending + $progress->pending,
                $total->inProgress + $progress->inProgress,
                $total->submitted + $progress->submitted,
                $total->declinedConflict + $progress->declinedConflict,
            );
        }

        return $total;
    }

    /**
     * Avance de cada evaluador con asignaciones en el evento, por identificador de evaluador, en una sola
     * consulta agrupada. Un evaluador sin asignaciones no aparece. Exige la organización en contexto.
     *
     * @param  string|null  $trackId  solo las propuestas de ese eje
     * @param  string|null  $callId  solo las propuestas de esa convocatoria
     * @return array<string, self>
     */
    public static function forEvent(Event $event, ?string $trackId = null, ?string $callId = null): array
    {
        $rows = ReviewAssignment::query()
            ->where('event_id', $event->id)
            // Como el panel del evaluador (`ReviewerAssignments`): una propuesta eliminada no cuenta.
            ->whereHas('submission', fn (Builder $submissions) => $submissions
                ->when($trackId !== null, fn (Builder $query) => $query->where('track_id', $trackId))
                ->when($callId !== null, fn (Builder $query) => $query->where('call_for_submissions_id', $callId)))
            ->selectRaw('reviewer_id, status, count(*) as total')
            ->groupBy('reviewer_id', 'status')
            ->toBase()
            ->get();

        /** @var array<string, array<string, int>> $byReviewer */
        $byReviewer = [];

        foreach ($rows as $row) {
            $byReviewer[(string) $row->reviewer_id][(string) $row->status] = (int) $row->total;
        }

        return array_map(
            static fn (array $counts): self => new self(
                $counts[ReviewAssignmentStatus::Pending->value] ?? 0,
                $counts[ReviewAssignmentStatus::InProgress->value] ?? 0,
                $counts[ReviewAssignmentStatus::Submitted->value] ?? 0,
                $counts[ReviewAssignmentStatus::DeclinedConflict->value] ?? 0,
            ),
            $byReviewer,
        );
    }
}
