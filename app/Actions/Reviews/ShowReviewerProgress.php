<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Enums\EventAssignmentRole;
use App\Enums\ReviewAssignmentStatus;
use App\Models\Event;
use App\Models\EventAssignment;
use App\Models\Organization;
use App\Models\Review;
use App\Models\ReviewAssignment;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Reviews\ReviewerProgress;
use App\Support\Reviews\ReviewerProgressAssignment;
use App\Support\Reviews\ReviewerProgressDetailReport;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Detalle del avance de un evaluador (plan 011, H-011-6, CA-011-58 y 59). Solo lectura. Se ejecuta en la
 * organización DEL EVENTO y exige `reviews.view_progress`: la autorización va ANTES de buscar a la persona, para no
 * revelar con un 404 quién existe a quien no puede verlo.
 *
 * Lista las asignaciones del evaluador en el evento (también las canceladas y las de conflicto, con su estado) con
 * la fecha de su última actividad: la de su evaluación si la tiene y, si no, la del último cambio de la asignación
 * (nada para una pendiente). NO lee calificaciones ni comentarios: eso exige `reviews.view_results`. Paginado con
 * máximo 100.
 *
 * @throws AuthorizationException sin `reviews.view_progress` en la organización del evento
 * @throws ModelNotFoundException si la persona no es evaluadora de ESTE evento (de otro evento, de otra organización o inexistente)
 */
final class ShowReviewerProgress
{
    use AuthorizesInOrganization;

    private const MAX_PER_PAGE = 100;

    public function handle(User $actor, Event $event, string $reviewerId, int $perPage = 25, ?int $page = null): ReviewerProgressDetailReport
    {
        $organization = Organization::query()->findOrFail($event->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $event, $reviewerId, $perPage, $page): ReviewerProgressDetailReport {
            $this->authorizeActor($actor, 'viewProgress', Review::class, $event);

            if (! Str::isUuid($reviewerId)) {
                throw (new ModelNotFoundException)->setModel(EventAssignment::class, [$reviewerId]);
            }

            $reviewer = EventAssignment::query()
                ->where('event_id', $event->id)
                ->where('role', EventAssignmentRole::Reviewer)
                ->where('user_id', $reviewerId)
                ->with('user:id,name,email')
                ->firstOrFail()
                ->user;

            $assignments = ReviewAssignment::query()
                ->where('event_id', $event->id)
                ->where('reviewer_id', $reviewerId)
                ->whereHas('submission')
                ->with('submission:id,title')
                ->addSelect(['review_assignments.*', 'review_updated_at' => Review::query()
                    ->select('updated_at')
                    ->whereColumn('reviews.review_assignment_id', 'review_assignments.id')
                    ->limit(1)])
                ->orderByDesc('review_assignments.updated_at')
                ->orderBy('review_assignments.id')
                ->paginate(max(1, min($perPage, self::MAX_PER_PAGE)), ['*'], 'page', $page)
                ->through(static fn (ReviewAssignment $assignment): ReviewerProgressAssignment => new ReviewerProgressAssignment(
                    $assignment->submission_id,
                    $assignment->submission->title ?? '',
                    $assignment->status,
                    self::lastActivity($assignment),
                ));

            return new ReviewerProgressDetailReport(
                $reviewer->id,
                $reviewer->name,
                $reviewer->email,
                ReviewerProgress::forEvent($event)[$reviewerId] ?? new ReviewerProgress,
                $assignments,
            );
        });
    }

    private static function lastActivity(ReviewAssignment $assignment): ?CarbonInterface
    {
        $reviewUpdatedAt = $assignment->getAttribute('review_updated_at');

        if (is_string($reviewUpdatedAt)) {
            return Carbon::parse($reviewUpdatedAt, 'UTC');
        }

        return $assignment->status === ReviewAssignmentStatus::Pending ? null : $assignment->updated_at;
    }
}
