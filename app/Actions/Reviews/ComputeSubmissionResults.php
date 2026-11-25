<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Enums\CriterionKind;
use App\Enums\ReviewAssignmentStatus;
use App\Enums\SubmissionOrigin;
use App\Enums\SubmissionStatus;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Review;
use App\Models\ReviewAnswer;
use App\Models\Rubric;
use App\Models\RubricCriterion;
use App\Models\Submission;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Reviews\CriterionDiscrepancy;
use App\Support\Reviews\ReviewCoverage;
use App\Support\Reviews\ReviewEventFilters;
use App\Support\Reviews\ReviewScore;
use App\Support\Reviews\SubmissionResult;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Resultados de las propuestas de un evento (plan 011, H-011-5, CA-011-22 a 24). Solo lectura. Se ejecuta en la
 * organización DEL EVENTO y exige `reviews.view_results`; los comentarios de los evaluadores viajan en el
 * resultado, así que el mismo permiso los protege.
 *
 * Qué cuenta: la evaluación VIGENTE (`revision >= 1`) de una asignación `submitted`; nunca un borrador, ni una
 * asignación `cancelled` o `declined_conflict`. Las reevaluaciones ya reemplazaron a la versión anterior.
 *
 * Cálculo, por evaluación y con los criterios de la versión de rúbrica con que se hizo (DA-2):
 * - promedio ponderado = Σ(peso × calificación) / Σ(peso) sobre sus criterios de calificación (la escala es «mayor
 *   es mejor»; `rubric_criteria` no guarda otra dirección, DT-38);
 * - porcentaje = Σ(peso × (calificación − mínimo) / (máximo − mínimo)) / Σ(peso) × 100.
 * Por propuesta: promedio y porcentaje son la media de las evaluaciones, y la desviación es la estándar poblacional
 * de sus promedios ponderados. Una propuesta con menos evaluaciones que el mínimo de su tipo (`ReviewCoverage`) es
 * «incompleta» y no entra al ranking como definitiva; el ranking ordena por porcentaje (empates comparten posición).
 * Una discrepancia es un criterio en el que dos evaluadores difieren en MÁS que el `discrepancy_threshold` de su rúbrica.
 *
 * Entran las propuestas `origin = call` no retiradas con al menos una asignación que no esté cancelada. El resultado
 * es completo (las completas por posición y luego las incompletas): quien lo muestra pagina con máximo 100. Todo se
 * lee en un número fijo de consultas, sin una por propuesta.
 *
 * @throws AuthorizationException sin `reviews.view_results` en la organización del evento
 * @throws ValidationException si un filtro (`track_id`, `call_id`) no es del evento
 * @throws LogicException si una evaluación vigente no tiene criterios de calificación (dato corrupto, no se oculta)
 */
final class ComputeSubmissionResults
{
    use AuthorizesInOrganization;

    /**
     * @param  array<string, mixed>  $filters  `track_id` y `call_id`, opcionales
     * @return list<SubmissionResult>
     */
    public function handle(User $actor, Event $event, array $filters = []): array
    {
        $organization = Organization::query()->findOrFail($event->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $event, $filters): array {
            $this->authorizeActor($actor, 'viewResults', Review::class, $event);

            $filter = ReviewEventFilters::validated($event, $filters);
            $submissions = $this->submissions($event, $filter);
            $reviews = $this->validReviews($event, $submissions->keys()->all());
            $criteria = $this->criteriaOf($reviews);
            $rubrics = Rubric::query()->whereIn('id', $criteria->pluck('rubric_id')->unique()->all())->get()->keyBy('id');
            $names = User::query()
                ->whereIn('id', $reviews->flatten(1)->map(static fn (Review $review): string => $review->assignment->reviewer_id)->unique()->all())
                ->pluck('name', 'id');
            $coverage = ReviewCoverage::for($event);

            $results = [];

            foreach ($submissions as $submission) {
                $scores = $reviews->get($submission->id, collect())
                    ->map(fn (Review $review): ReviewScore => $this->score($review, $criteria, $rubrics, (string) $names->get($review->assignment->reviewer_id)))
                    ->sortBy('reviewerName')
                    ->values()
                    ->all();

                $results[] = $this->result($submission, $scores, $coverage->minimumFor($submission->submission_type_id), $this->discrepancies($scores, $criteria, $rubrics));
            }

            return $this->ranked($results);
        });
    }

    /** @return Collection<string, Submission> por identificador */
    private function submissions(Event $event, ReviewEventFilters $filter): Collection
    {
        return Submission::query()
            ->where('event_id', $event->id)
            ->where('origin', SubmissionOrigin::Call)
            ->where('status', '!=', SubmissionStatus::Withdrawn)
            ->when($filter->trackId !== null, fn (Builder $query) => $query->where('track_id', $filter->trackId))
            ->when($filter->callId !== null, fn (Builder $query) => $query->where('call_for_submissions_id', $filter->callId))
            ->whereHas('reviewAssignments', fn (Builder $assignments) => $assignments->where('status', '!=', ReviewAssignmentStatus::Cancelled))
            ->get(['id', 'title', 'status', 'track_id', 'call_for_submissions_id', 'submission_type_id'])
            ->keyBy('id');
    }

    /**
     * Evaluaciones vigentes del evento agrupadas por propuesta, con su asignación y sus respuestas.
     *
     * @param  list<string>  $submissionIds
     * @return Collection<array-key, Collection<int, Review>>
     */
    private function validReviews(Event $event, array $submissionIds): Collection
    {
        $wanted = array_flip($submissionIds);

        return Review::query()
            ->where('revision', '>=', 1)
            ->whereHas('assignment', fn (Builder $assignments) => $assignments
                ->where('event_id', $event->id)
                ->where('status', ReviewAssignmentStatus::Submitted))
            ->with(['assignment', 'answers'])
            ->get()
            ->toBase()
            ->filter(static fn (Review $review): bool => isset($wanted[$review->assignment->submission_id]))
            ->groupBy(static fn (Review $review): string => $review->assignment->submission_id);
    }

    /**
     * @param  Collection<array-key, Collection<int, Review>>  $reviews
     * @return Collection<string, RubricCriterion>
     */
    private function criteriaOf(Collection $reviews): Collection
    {
        $rubricIds = $reviews->flatten(1)->map(static fn (Review $review): string => $review->rubric_id)->unique()->values()->all();

        return RubricCriterion::query()->whereIn('rubric_id', $rubricIds)->orderBy('position')->get()->keyBy('id');
    }

    /**
     * @param  Collection<string, RubricCriterion>  $criteria
     * @param  Collection<string, Rubric>  $rubrics
     */
    private function score(Review $review, Collection $criteria, Collection $rubrics, string $reviewerName): ReviewScore
    {
        $weights = 0.0;
        $weighted = 0.0;
        $normalized = 0.0;
        $scores = [];
        $comments = [];

        foreach ($review->answers->sortBy(static fn (ReviewAnswer $answer): int => $criteria->get($answer->rubric_criterion_id)->position ?? 0) as $answer) {
            $criterion = $criteria->get($answer->rubric_criterion_id) ?? throw new LogicException("La respuesta {$answer->id} apunta a un criterio fuera de la rúbrica de la evaluación.");
            $label = $criterion->labelFor(app()->getLocale());

            if ($criterion->kind === CriterionKind::Text) {
                if ($answer->value_text !== null) {
                    $comments[] = ['label' => $label, 'text' => $answer->value_text];
                }

                continue;
            }

            $value = $answer->value_int ?? throw new LogicException("La calificación {$answer->id} no tiene valor.");
            $weight = (float) $criterion->weight;
            $weights += $weight;
            $weighted += $weight * $value;
            $normalized += $weight * ($value - $criterion->scale_min) / ($criterion->scale_max - $criterion->scale_min);
            $scores[] = ['criterion_id' => $criterion->id, 'label' => $label, 'value' => $value];
        }

        if ($weights <= 0.0) {
            throw new LogicException("La evaluación {$review->id} vigente no tiene criterios de calificación.");
        }

        return new ReviewScore(
            $review->assignment->reviewer_id,
            $reviewerName,
            $rubrics->get($review->rubric_id)->version ?? 0,
            $weighted / $weights,
            $normalized / $weights * 100,
            $scores,
            $comments,
        );
    }

    /**
     * Criterios con diferencia mayor que el umbral de su rúbrica entre dos evaluadores o más.
     *
     * @param  list<ReviewScore>  $scores
     * @param  Collection<string, RubricCriterion>  $criteria
     * @param  Collection<string, Rubric>  $rubrics
     * @return list<CriterionDiscrepancy>
     */
    private function discrepancies(array $scores, Collection $criteria, Collection $rubrics): array
    {
        /** @var array<string, list<array{reviewer_id: string, reviewer_name: string, value: int, label: string}>> $byCriterion */
        $byCriterion = [];

        foreach ($scores as $score) {
            foreach ($score->scores as $answer) {
                $byCriterion[$answer['criterion_id']][] = [
                    'reviewer_id' => $score->reviewerId,
                    'reviewer_name' => $score->reviewerName,
                    'value' => $answer['value'],
                    'label' => $answer['label'],
                ];
            }
        }

        $found = [];

        foreach ($byCriterion as $criterionId => $answers) {
            $values = array_column($answers, 'value');
            $spread = max($values) - min($values);
            $threshold = $rubrics->get($criteria->get($criterionId)->rubric_id ?? '')->discrepancy_threshold ?? null;

            if (count($answers) < 2 || $threshold === null || $spread <= $threshold) {
                continue;
            }

            $found[] = new CriterionDiscrepancy(
                (string) $criterionId,
                $answers[0]['label'],
                $spread,
                $threshold,
                array_map(static fn (array $answer): array => ['reviewer_id' => $answer['reviewer_id'], 'reviewer_name' => $answer['reviewer_name'], 'value' => $answer['value']], $answers),
            );
        }

        return $found;
    }

    /**
     * @param  list<ReviewScore>  $scores
     * @param  list<CriterionDiscrepancy>  $discrepancies
     */
    private function result(Submission $submission, array $scores, int $minimum, array $discrepancies): SubmissionResult
    {
        $count = count($scores);
        $averages = array_map(static fn (ReviewScore $score): float => $score->average, $scores);
        $average = $count === 0 ? null : array_sum($averages) / $count;
        $deviation = $average === null ? null : sqrt(array_sum(array_map(static fn (float $value): float => ($value - $average) ** 2, $averages)) / $count);

        return new SubmissionResult(
            $submission->id,
            $submission->title,
            $submission->status,
            $submission->track_id,
            $submission->call_for_submissions_id,
            $count,
            $minimum,
            $count < $minimum,
            $average,
            $count === 0 ? null : array_sum(array_map(static fn (ReviewScore $score): float => $score->percentage, $scores)) / $count,
            $deviation,
            $scores,
            $discrepancies,
        );
    }

    /**
     * Las completas por porcentaje descendente con su posición y, después, las incompletas sin posición.
     *
     * @param  list<SubmissionResult>  $results
     * @return list<SubmissionResult>
     */
    private function ranked(array $results): array
    {
        $byScore = static fn (SubmissionResult $a, SubmissionResult $b): int => [-($a->percentage ?? -1.0), $a->title, $a->submissionId] <=> [-($b->percentage ?? -1.0), $b->title, $b->submissionId];

        $complete = array_values(array_filter($results, static fn (SubmissionResult $result): bool => ! $result->incomplete));
        $incomplete = array_values(array_filter($results, static fn (SubmissionResult $result): bool => $result->incomplete));
        usort($complete, $byScore);
        usort($incomplete, $byScore);

        $rank = 0;
        $previous = null;

        foreach ($complete as $index => $result) {
            $score = round((float) $result->percentage, 4);
            $rank = $score === $previous ? $rank : $index + 1;
            $previous = $score;
            $complete[$index] = $result->withRank($rank);
        }

        return [...$complete, ...$incomplete];
    }
}
