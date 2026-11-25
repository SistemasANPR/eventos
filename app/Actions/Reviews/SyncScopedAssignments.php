<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Enums\ReviewAssignmentScope;
use App\Enums\ReviewAssignmentSkipReason;
use App\Enums\ReviewAssignmentSource;
use App\Models\Event;
use App\Models\Organization;
use App\Models\ReviewAssignment;
use App\Models\ReviewScopeRule;
use App\Models\Submission;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Reviews\EventReviewers;
use App\Support\Reviews\ReviewAssignmentReport;
use App\Support\Reviews\SkippedAssignment;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Aplica las reglas permanentes de alcance de un evento (plan 011, F3b, DA-6, DA-8; CA-011-42): asigna a cada
 * evaluador de una regla las propuestas elegibles de su eje o convocatoria que aún no tiene, con
 * `source = scope`, SIN correo (la propuesta aparece en su tablero) y pasando la propuesta a `under_review`
 * con `MarkSubmissionUnderReview` (CA-011-9).
 *
 * Tres usos, una sola Action:
 * - envío de una propuesta nueva: `SyncScopedAssignmentsJob` la llama con la propuesta y sin persona (`$actor = null`);
 * - botón manual «Asignar propuestas nuevas»: la llama una persona sin propuesta, y recorre todo el evento;
 * - reconciliación de una regla, por la misma vía manual.
 *
 * Contrato:
 * - `$actor = null` es el sistema (el job): no autoriza, así que solo debe invocarse desde código del servidor,
 *   nunca desde una ruta. Con persona, autoriza con la Policy (`review_assignments.manage`); sin permiso, 403.
 * - Idempotente: ejecutarla dos veces seguidas no duplica nada (única `(propuesta, evaluador)` en la base y se
 *   comprueba antes). Lo ya asignado NO se informa: aquí es el caso normal y no una omisión.
 * - Un evaluador de una regla que ya no es integrante activo ni evaluador del evento se omite con el motivo
 *   `reviewer_unavailable` en el informe (la regla se conserva); nunca falla ni se pierde en silencio.
 * - Una propuesta no elegible (invitada, retirada o decidida) o con conflicto de correo se informa como en
 *   `AssignReviewers`. Con `$submission`, solo se evalúa esa propuesta y solo contra las reglas que la cubren.
 * - Todo en una transacción con las propuestas bloqueadas. No cambia las reglas.
 *
 * @throws AuthorizationException si quien la invoca es una persona sin `review_assignments.manage` en la organización del evento
 */
final class SyncScopedAssignments
{
    use AuthorizesInOrganization;

    public function __construct(private readonly WriteReviewAssignments $writer) {}

    public function handle(?User $actor, Event $event, ?Submission $submission = null): ReviewAssignmentReport
    {
        $organization = Organization::query()->findOrFail($event->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $event, $organization, $submission): ReviewAssignmentReport {
            if ($actor instanceof User) {
                $this->authorizeActor($actor, 'create', ReviewAssignment::class, $event);
            }

            $event = Event::query()->findOrFail($event->id);
            $operationId = (string) Str::uuid();
            $rules = ReviewScopeRule::query()->where('event_id', $event->id)->get();

            return DB::transaction(fn (): ReviewAssignmentReport => $this->sync($actor, $organization, $event, $rules, $submission, $operationId));
        });
    }

    /**
     * @param  Collection<int, ReviewScopeRule>  $rules
     */
    private function sync(?User $actor, Organization $organization, Event $event, Collection $rules, ?Submission $submission, string $operationId): ReviewAssignmentReport
    {
        $assigned = [];
        $skipped = [];

        foreach ($rules->groupBy(fn (ReviewScopeRule $rule): string => $rule->scope_type->value.'|'.$rule->scopeId()) as $group) {
            $first = $group->first();

            if (! $first instanceof ReviewScopeRule) {
                continue;
            }

            $reviewerIds = $group->pluck('reviewer_id')->all();
            $available = EventReviewers::available($organization, $event, $reviewerIds);
            $reviewers = array_values($available);

            foreach (array_diff($reviewerIds, array_keys($available)) as $missingId) {
                $skipped[] = new SkippedAssignment($first->scopeId(), '', $missingId, User::query()->whereKey($missingId)->value('name'), ReviewAssignmentSkipReason::ReviewerUnavailable);
            }

            $submissions = $this->submissionsInScope($event, $first, $submission);

            if ($reviewers === [] || $submissions->isEmpty()) {
                continue;
            }

            $report = $this->writer->handle($actor, $event, $submissions, $reviewers, ReviewAssignmentSource::Scope, $first->scope_type, $operationId);

            array_push($assigned, ...$report->assigned);
            // Lo ya asignado es el estado normal de una regla vigente: no se lista como omisión.
            array_push($skipped, ...array_filter(
                $report->skipped,
                static fn (SkippedAssignment $item): bool => $item->reason !== ReviewAssignmentSkipReason::AlreadyAssigned,
            ));
        }

        return new ReviewAssignmentReport($operationId, $assigned, $skipped);
    }

    /**
     * Las propuestas del alcance de la regla, bloqueadas y con sus participantes; con `$only`, solo esa si cae
     * dentro del alcance (una propuesta de otro eje o convocatoria no se toca).
     *
     * @return Collection<int, Submission>
     */
    private function submissionsInScope(Event $event, ReviewScopeRule $rule, ?Submission $only): Collection
    {
        $query = Submission::query()->where('event_id', $event->id)->with('participants')->orderBy('id')->lockForUpdate();

        $query = $rule->scope_type === ReviewAssignmentScope::Track
            ? $query->where('track_id', $rule->track_id)
            : $query->where('call_for_submissions_id', $rule->call_id);

        return $only instanceof Submission ? $query->whereKey($only->id)->get() : $query->get();
    }
}
