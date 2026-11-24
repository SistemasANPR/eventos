<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Actions\Program\PromoteSubmissionToProgram;
use App\Enums\SubmissionOrigin;
use App\Enums\SubmissionStatus;
use App\Http\Requests\Submissions\BulkDecideRequest;
use App\Models\DecisionBatch;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Submissions\AuthorComment;
use App\Support\Submissions\BulkDecisionReport;
use App\Support\Submissions\SubmissionReference;
use App\Support\Submissions\SubmissionTransitions;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Decide en masa sobre una selección de propuestas de un evento (plan 012, F2, H-012-2, CA-012-9 a 11):
 * acepta, pone en lista de espera o rechaza todas en UNA transacción. Se ejecuta en la organización DEL
 * EVENTO y exige `submissions.decide` (la Policy, no la interfaz).
 *
 * - Todo o nada (CA-012-10): se bloquean las filas y se comprueba cada una contra la tabla de transiciones
 *   (`SubmissionTransitions`) y su origen; si alguna no es elegible se rechaza TODA la operación con un
 *   error de validación que dice cuáles. La pantalla filtra antes las no elegibles y las informa como
 *   omitidas, así que aquí un estado que cambió entre la confirmación y la aplicación también se rechaza.
 * - Máximo `BulkDecideRequest::MAX_SUBMISSIONS` propuestas por operación (CA-012-11).
 * - Un identificador que no es del evento (o de otra organización) responde 404 y no cambia nada.
 * - Todas comparten un `decision_batch_id` (el lote guarda quién, cuándo, el filtro y el total, sin datos
 *   personales) y cada una deja su cambio en el historial con la misma auditoría que una decisión individual.
 *   Al aceptar, cada una pasa al programa en la misma transacción (`PromoteSubmissionToProgram`).
 * - Los correos de resultado no salen al decidir: se publican con `PublishResults`, salvo que la convocatoria
 *   ya haya publicado (DA-2); ese aviso ocurre DESPUÉS de confirmar y un fallo no deshace el lote.
 *
 * Datos de entrada (`BulkDecideRequest`): `to` (`accepted|waitlisted|rejected`), `submission_ids`, opcionales
 * `author_comment` (el mismo para todas) y `filter` (solo para la auditoría).
 *
 * @throws AuthorizationException si quien decide no tiene `submissions.decide` en la organización del evento
 * @throws ValidationException si la entrada es inválida, pasa del máximo o alguna propuesta no es elegible
 * @throws ModelNotFoundException si una propuesta no es del evento
 */
final class BulkDecideSubmissions
{
    use AuthorizesInOrganization;

    /** Propuestas que la lista de la validación nombra una por una; el resto se resume. */
    private const MAX_NAMED = 10;

    public function __construct(
        private readonly ChangeSubmissionStatus $change,
        private readonly PromoteSubmissionToProgram $promote,
        private readonly PublishSubmissionResult $publishResult,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, Event $event, array $data): BulkDecisionReport
    {
        $organization = Organization::query()->findOrFail($event->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $event, $data): BulkDecisionReport {
            $input = Validator::make($data, (new BulkDecideRequest)->rules())->validate();
            $to = SubmissionStatus::from((string) $input['to']);
            $comment = AuthorComment::clean(is_string($input['author_comment'] ?? null) ? $input['author_comment'] : null);
            $ids = array_values(array_map(strval(...), (array) $input['submission_ids']));

            [$batch, $decided, $byFrom] = DB::transaction(fn (): array => $this->decide($actor, $event, $to, $ids, $comment, $this->filterOf($input, $to)));

            $publishedNow = 0;

            foreach ($decided as $submission) {
                $publishedNow += $this->publishResult->afterDecision($submission) ? 1 : 0;
            }

            return new BulkDecisionReport($batch->id, $to, count($decided), $byFrom, $publishedNow);
        });
    }

    /**
     * @param  list<string>  $ids
     * @param  array<string, mixed>  $filter
     * @return array{0: DecisionBatch, 1: list<Submission>, 2: array<string, int>}
     */
    private function decide(User $actor, Event $event, SubmissionStatus $to, array $ids, ?string $comment, array $filter): array
    {
        $submissions = Submission::query()
            ->where('event_id', $event->id)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($submissions->count() !== count($ids)) {
            throw (new ModelNotFoundException)->setModel(Submission::class, $ids);
        }

        $this->authorizeActor($actor, 'decide', $submissions->firstOrFail());
        $this->assertAllEligible($submissions->all(), $to);

        $batch = new DecisionBatch;
        $batch->forceFill([
            'organization_id' => $event->organization_id,
            'event_id' => $event->id,
            'actor_user_id' => $actor->id,
            'filter' => $filter,
            'total' => $submissions->count(),
            'created_at' => now(),
        ])->save();

        $byFrom = [];

        foreach ($submissions as $submission) {
            $byFrom[$submission->status->value] = ($byFrom[$submission->status->value] ?? 0) + 1;

            $this->change->handle($actor, $submission, $to, $comment, null, false, $batch->id);

            if ($to === SubmissionStatus::Accepted) {
                $this->promote->handle($actor, $submission);
            }
        }

        activity()
            ->causedBy($actor)
            ->performedOn($batch)
            ->event('submissions_decided_in_bulk')
            ->withProperties([
                'organization_id' => $event->organization_id,
                'event_id' => $event->id,
                'decision_batch_id' => $batch->id,
                'to' => $to->value,
                'total' => $batch->total,
                'by_from_status' => $byFrom,
                'filter' => $filter,
                'has_author_comment' => $comment !== null,
            ])
            ->log('submissions_decided_in_bulk');

        return [$batch, Submission::query()->whereIn('id', $ids)->get()->all(), $byFrom];
    }

    /**
     * @param  list<Submission>  $submissions
     *
     * @throws ValidationException con las propuestas no elegibles, sin cambiar nada
     */
    private function assertAllEligible(array $submissions, SubmissionStatus $to): void
    {
        $ineligible = array_values(array_filter(
            $submissions,
            static fn (Submission $submission): bool => $submission->origin !== SubmissionOrigin::Call
                || ! SubmissionTransitions::isAllowed($submission->status, $to),
        ));

        if ($ineligible === []) {
            return;
        }

        $named = array_map(
            static fn (Submission $submission): string => SubmissionReference::for($submission).' «'.$submission->title.'» ('.$submission->status->getLabel().')',
            array_slice($ineligible, 0, self::MAX_NAMED),
        );

        $more = count($ineligible) - count($named);

        throw ValidationException::withMessages(['submission_ids' => __('submission_decisions.bulk.errors.not_eligible', [
            'count' => count($ineligible),
            'to' => $to->getLabel(),
            'list' => implode('; ', $named).($more > 0 ? '; '.__('submission_decisions.bulk.errors.and_more', ['count' => $more]) : ''),
        ])]);
    }

    /**
     * El filtro que se guarda en el lote: lo validado más el estado de destino. Sin datos personales.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function filterOf(array $input, SubmissionStatus $to): array
    {
        $filter = is_array($input['filter'] ?? null) ? $input['filter'] : [];

        return ['mode' => 'selection', ...array_filter($filter, static fn (mixed $value): bool => $value !== null && $value !== ''), 'to' => $to->value];
    }
}
