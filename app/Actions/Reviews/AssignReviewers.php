<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Actions\Notifications\SendNotification;
use App\Enums\ReviewAssignmentScope;
use App\Enums\ReviewAssignmentSource;
use App\Http\Requests\Reviews\AssignReviewersRequest;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\Organization;
use App\Models\ReviewAssignment;
use App\Models\ReviewScopeRule;
use App\Models\Submission;
use App\Models\Track;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Notifications\MailRecipient;
use App\Support\Reviews\EventReviewers;
use App\Support\Reviews\ReviewAssignmentReport;
use App\Support\Reviews\ReviewerDashboardUrl;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Asigna evaluadores a propuestas de un evento (plan 011, H-011-2, DA-5, DA-6 y DA-8), con un alcance:
 * propuestas elegidas (individual o en lote), un eje o una convocatoria. Por eje o convocatoria es una
 * EXPANSIÓN en este momento y, con `save_rule`, guarda además la regla permanente (`review_assignment_scopes`):
 * las propuestas que lleguen después las asigna `SyncScopedAssignments` (F3b). Se ejecuta en la organización
 * DEL EVENTO.
 *
 * Reglas:
 * - Autoriza con la Policy (`review_assignments.manage`) antes de tocar nada; no confía en la interfaz.
 * - Cada evaluador debe ser integrante activo de la organización y tener un `event_assignments` de rol
 *   `reviewer` en el evento (CA-011-10); si alguno no lo es, se rechaza TODA la operación y no se crea nada.
 * - Un identificador de propuesta, eje o convocatoria de otro evento u otra organización responde 404
 *   (`ModelNotFoundException`) y no se crea nada (CA-011-47).
 * - Son elegibles las propuestas con `origin = call` en estado `submitted` o `under_review`; las demás se
 *   omiten (CA-011-40). Una pareja cuyo evaluador comparte correo (sin distinguir mayúsculas) con el autor
 *   o con cualquier participante se omite (CA-011-7); las demás se crean (CA-011-41). Ver `WriteReviewAssignments`.
 * - Idempotente: repetir la operación no duplica nada (ni asignaciones ni reglas); lo ya asignado se informa
 *   como omitido. Una asignación cancelada con evaluación conservada no se reactiva aquí (DT-20).
 * - La regla (`save_rule = true`, solo por eje o convocatoria) se guarda en la misma transacción, una por
 *   evaluador y alcance, aunque no quede ninguna propuesta elegible hoy. La expansión de este momento es
 *   `source = manual` (CA-011-38); las propuestas posteriores serán `source = scope` (CA-011-42).
 * - Auditoría: un registro `review_assignments_created` por propuesta con los evaluadores nuevos y un
 *   `review_scope_rule_created` por regla nueva.
 * - Un solo correo `reviewer.assignment` por evaluador y por operación, con la cantidad nueva y el enlace a
 *   su tablero, DESPUÉS de confirmar la transacción y siempre por `SendNotification` (CA-011-44). Si encolarlo
 *   falla, las asignaciones persisten y el fallo queda en el informe (`notificationFailures`) y en el log.
 *
 * Datos de entrada (`AssignReviewersRequest`): `scope` (`submission|track|call`), `reviewer_ids`, y según el
 * alcance `submission_ids`, `track_id` o `call_id`; opcional `save_rule` (booleano, por defecto falso).
 *
 * @throws AuthorizationException si quien asigna no tiene `review_assignments.manage` en la organización del evento
 * @throws ValidationException si la entrada es inválida o algún evaluador no es evaluador del evento
 * @throws ModelNotFoundException si una propuesta, eje o convocatoria no es del evento
 */
final class AssignReviewers
{
    use AuthorizesInOrganization;

    public function __construct(
        private readonly WriteReviewAssignments $writer,
        private readonly SendNotification $notify,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, Event $event, array $data): ReviewAssignmentReport
    {
        $organization = Organization::query()->findOrFail($event->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $event, $organization, $data): ReviewAssignmentReport {
            $this->authorizeActor($actor, 'create', ReviewAssignment::class, $event);

            $input = Validator::make($data, (new AssignReviewersRequest)->rules())->validate();
            $event = Event::query()->findOrFail($event->id);
            $scope = ReviewAssignmentScope::from((string) $input['scope']);
            $reviewers = $this->eligibleReviewers($organization, $event, $this->strings($input['reviewer_ids']));
            $saveRule = filter_var($input['save_rule'] ?? false, FILTER_VALIDATE_BOOLEAN) && $scope !== ReviewAssignmentScope::Submission;

            $report = DB::transaction(fn (): ReviewAssignmentReport => $this->assign($actor, $event, $scope, $input, $reviewers, $saveRule));

            return $this->notifyReviewers($report, $organization, $event, $reviewers);
        });
    }

    /**
     * Los evaluadores pedidos, en el orden recibido. Todos deben ser integrantes activos de la organización
     * y tener rol de evento `reviewer` (CA-011-10): basta uno que no para rechazar la operación entera.
     *
     * @param  list<string>  $reviewerIds
     * @return list<User>
     */
    private function eligibleReviewers(Organization $organization, Event $event, array $reviewerIds): array
    {
        $available = EventReviewers::available($organization, $event, $reviewerIds);
        $reviewers = [];

        foreach ($reviewerIds as $id) {
            $reviewer = $available[$id] ?? null;

            if (! $reviewer instanceof User) {
                throw ValidationException::withMessages(['reviewer_ids' => __('review_assignments.errors.reviewer_not_in_event')]);
            }

            $reviewers[] = $reviewer;
        }

        return $reviewers;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<User>  $reviewers
     */
    private function assign(User $actor, Event $event, ReviewAssignmentScope $scope, array $input, array $reviewers, bool $saveRule): ReviewAssignmentReport
    {
        $operationId = (string) Str::uuid();
        $submissions = $this->submissionsInScope($event, $scope, $input);
        $report = $this->writer->handle($actor, $event, $submissions, $reviewers, ReviewAssignmentSource::Manual, $scope, $operationId);

        return $saveRule ? $report->withRulesCreated($this->saveRules($actor, $event, $scope, $input, $reviewers)) : $report;
    }

    /**
     * Guarda una regla por evaluador y alcance; si ya existe la regla, no se duplica ni se cuenta.
     *
     * @param  array<string, mixed>  $input
     * @param  list<User>  $reviewers
     * @return int reglas nuevas
     */
    private function saveRules(User $actor, Event $event, ReviewAssignmentScope $scope, array $input, array $reviewers): int
    {
        $column = $scope === ReviewAssignmentScope::Track ? 'track_id' : 'call_id';
        $scopeId = (string) ($input[$column] ?? '');
        $created = 0;

        foreach ($reviewers as $reviewer) {
            $exists = ReviewScopeRule::query()
                ->where('reviewer_id', $reviewer->id)
                ->where($column, $scopeId)
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                continue;
            }

            $rule = new ReviewScopeRule;
            $rule->forceFill([
                'event_id' => $event->id,
                'reviewer_id' => $reviewer->id,
                'scope_type' => $scope,
                $column => $scopeId,
                'created_by' => $actor->id,
            ])->save();

            activity()
                ->causedBy($actor)
                ->performedOn($rule)
                ->event('review_scope_rule_created')
                ->withProperties([
                    'organization_id' => $event->organization_id,
                    'event_id' => $event->id,
                    'reviewer_id' => $reviewer->id,
                    'scope' => $scope->value,
                    'scope_id' => $scopeId,
                ])
                ->log('review_scope_rule_created');

            $created++;
        }

        return $created;
    }

    /**
     * Las propuestas del alcance, con sus filas BLOQUEADAS hasta confirmar. Un identificador que no es del
     * evento (de otro evento, de otra organización o inexistente) produce 404 sin distinguir cuál.
     *
     * @param  array<string, mixed>  $input
     * @return Collection<int, Submission>
     */
    private function submissionsInScope(Event $event, ReviewAssignmentScope $scope, array $input): Collection
    {
        $query = Submission::query()->where('event_id', $event->id)->with('participants')->orderBy('id')->lockForUpdate();

        switch ($scope) {
            case ReviewAssignmentScope::Submission:
                $ids = $this->strings($input['submission_ids'] ?? []);
                $submissions = $query->whereIn('id', $ids)->get();

                if ($submissions->count() !== count($ids)) {
                    throw (new ModelNotFoundException)->setModel(Submission::class, $ids);
                }

                return $submissions;

            case ReviewAssignmentScope::Track:
                $track = Track::query()->where('event_id', $event->id)->findOrFail($input['track_id'] ?? null);

                return $query->where('track_id', $track->id)->get();

            case ReviewAssignmentScope::Call:
                $call = CallForSubmissions::query()->where('event_id', $event->id)->findOrFail($input['call_id'] ?? null);

                return $query->where('call_for_submissions_id', $call->id)->get();
        }
    }

    /**
     * Un correo `reviewer.assignment` por evaluador con asignaciones nuevas (DA-8). Si encolarlo falla, la
     * asignación ya está confirmada: el fallo se registra y se devuelve en el informe, no se oculta.
     *
     * @param  list<User>  $reviewers
     */
    private function notifyReviewers(ReviewAssignmentReport $report, Organization $organization, Event $event, array $reviewers): ReviewAssignmentReport
    {
        $counts = array_count_values(array_column($report->assigned, 'reviewer_id'));
        $failures = [];

        foreach ($reviewers as $reviewer) {
            $count = $counts[$reviewer->id] ?? 0;

            if ($count === 0) {
                continue;
            }

            try {
                $this->notify->handle(
                    key: 'reviewer.assignment',
                    recipient: new MailRecipient($reviewer->email, $reviewer->name, $event->default_locale),
                    variables: [
                        'reviewer_name' => $reviewer->name,
                        'event_name' => $event->getTranslation('name', $event->default_locale),
                        'assigned_count' => (string) $count,
                        'dashboard_url' => ReviewerDashboardUrl::for($organization),
                    ],
                    factId: $report->operationId,
                    organization: $organization,
                    nonce: Str::lower($reviewer->id),
                    eventId: $event->id,
                );
            } catch (Throwable $exception) {
                report($exception);
                $failures[] = $reviewer->id;
            }
        }

        return $failures === [] ? $report : $report->withNotificationFailures($failures);
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $values): array
    {
        return is_array($values) ? array_values(array_map(strval(...), $values)) : [];
    }
}
