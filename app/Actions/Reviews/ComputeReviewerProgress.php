<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Enums\EventAssignmentRole;
use App\Models\Event;
use App\Models\EventAssignment;
use App\Models\Organization;
use App\Models\Review;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Reviews\ReviewerProgress;
use App\Support\Reviews\ReviewerProgressReport;
use App\Support\Reviews\ReviewerProgressRow;
use App\Support\Reviews\ReviewEventFilters;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Avance por evaluador de un evento (plan 011, H-011-6, CA-011-25 y 55 a 57, 59). Solo lectura. Se ejecuta en la
 * organización DEL EVENTO y exige `reviews.view_progress`. Muestra estados y conteos, nunca calificaciones ni
 * comentarios (eso es de `ComputeSubmissionResults`, con `reviews.view_results`).
 *
 * Una fila por persona con asignación de evento de rol `reviewer` (también la que aún no tiene propuestas: sale
 * como «sin asignaciones»). El cálculo del porcentaje es de `ReviewerProgress`. Filtros opcionales por evaluador,
 * eje y convocatoria; el avance global del evento respeta el eje y la convocatoria, no el filtro de evaluador. Orden
 * por porcentaje (`asc`, el predeterminado, deja arriba a quien más se atrasa; `desc` al revés); sin asignaciones
 * va al final y el desempate es el nombre.
 *
 * @throws AuthorizationException sin `reviews.view_progress` en la organización del evento
 * @throws ValidationException si un filtro no es del evento o el orden no es `asc` ni `desc`
 */
final class ComputeReviewerProgress
{
    use AuthorizesInOrganization;

    /** @param  array<string, mixed>  $filters  `reviewer_id`, `track_id` y `call_id`, opcionales */
    public function handle(User $actor, Event $event, array $filters = [], string $direction = 'asc'): ReviewerProgressReport
    {
        $organization = Organization::query()->findOrFail($event->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $event, $filters, $direction): ReviewerProgressReport {
            $this->authorizeActor($actor, 'viewProgress', Review::class, $event);

            if (! in_array($direction, ['asc', 'desc'], true)) {
                throw ValidationException::withMessages(['sort' => __('review_results.invalid_filter')]);
            }

            $filter = ReviewEventFilters::validated($event, $filters);
            $progress = ReviewerProgress::forEvent($event, $filter->trackId, $filter->callId);

            $rows = EventAssignment::query()
                ->where('event_id', $event->id)
                ->where('role', EventAssignmentRole::Reviewer)
                ->with('user:id,name,email')
                ->get()
                ->filter(static fn (EventAssignment $assignment): bool => $assignment->user !== null)
                ->map(static fn (EventAssignment $assignment): ReviewerProgressRow => new ReviewerProgressRow(
                    $assignment->user_id,
                    $assignment->user->name,
                    $assignment->user->email,
                    $progress[$assignment->user_id] ?? new ReviewerProgress,
                ))
                ->values();

            $overall = ReviewerProgress::sum($rows->map(static fn (ReviewerProgressRow $row): ReviewerProgress => $row->progress));

            $sign = $direction === 'asc' ? 1 : -1;
            $sorted = $rows
                ->when($filter->reviewerId !== null, static fn ($rows) => $rows->filter(static fn (ReviewerProgressRow $row): bool => $row->reviewerId === $filter->reviewerId))
                ->sort(static function (ReviewerProgressRow $a, ReviewerProgressRow $b) use ($sign): int {
                    // Sin asignaciones siempre al final, sea cual sea el sentido.
                    $none = static fn (ReviewerProgressRow $row): int => $row->progress->hasAssignments() ? 0 : 1;
                    $ratio = static fn (ReviewerProgressRow $row): float => $row->progress->hasAssignments() ? $row->progress->submitted / $row->progress->active() : 0.0;

                    return [$none($a), $sign * $ratio($a), $a->name, $a->reviewerId] <=> [$none($b), $sign * $ratio($b), $b->name, $b->reviewerId];
                })
                ->values()
                ->all();

            return new ReviewerProgressReport($sorted, $overall);
        });
    }
}
