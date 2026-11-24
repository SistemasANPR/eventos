<?php

declare(strict_types=1);

namespace App\Support\Reviews;

use App\Enums\EventAssignmentRole;
use App\Enums\ReviewAssignmentStatus;
use App\Models\Event;
use App\Models\EventAssignment;
use App\Models\User;

/**
 * Resumen del tablero del evaluador (plan 011, CA-011-50): cuántas asignaciones tiene por estado y si el
 * periodo de evaluación de cada uno de sus eventos está abierto. Solo cuenta las propias
 * (`ReviewerAssignments`); el avance de los demás evaluadores no se muestra aquí (CA-011-60). Exige la
 * organización en contexto.
 */
final class ReviewerSummary
{
    /**
     * `assigned` es el total vigente: todas menos las canceladas, que ni aparecen.
     *
     * @return array{assigned: int, pending: int, in_progress: int, submitted: int, declined_conflict: int}
     */
    public static function counts(User $reviewer): array
    {
        /** @var array<string, int> $byStatus */
        $byStatus = ReviewerAssignments::for($reviewer)
            ->reorder()
            ->selectRaw('review_assignments.status as status, count(*) as total')
            ->groupBy('review_assignments.status')
            ->pluck('total', 'status')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();

        $count = static fn (ReviewAssignmentStatus $status): int => $byStatus[$status->value] ?? 0;

        return [
            'assigned' => array_sum($byStatus),
            'pending' => $count(ReviewAssignmentStatus::Pending),
            'in_progress' => $count(ReviewAssignmentStatus::InProgress),
            'submitted' => $count(ReviewAssignmentStatus::Submitted),
            'declined_conflict' => $count(ReviewAssignmentStatus::DeclinedConflict),
        ];
    }

    /**
     * Eventos en los que la persona es evaluadora y si su periodo de evaluación está abierto.
     *
     * @return list<array{id: string, name: string, open: bool}>
     */
    public static function periods(User $reviewer): array
    {
        return Event::query()
            ->whereIn('id', EventAssignment::query()
                ->where('user_id', $reviewer->id)
                ->where('role', EventAssignmentRole::Reviewer)
                ->select('event_id'))
            ->orderBy('starts_at')
            ->get()
            ->map(static fn (Event $event): array => [
                'id' => $event->id,
                'name' => (string) $event->getTranslation('name', app()->getLocale(), true),
                'open' => $event->reviewPeriodIsOpen(),
            ])
            ->all();
    }
}
