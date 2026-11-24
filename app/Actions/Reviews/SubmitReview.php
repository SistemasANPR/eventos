<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Enums\CriterionKind;
use App\Enums\ReviewAssignmentStatus;
use App\Exceptions\Domain\NoPublishedRubric;
use App\Exceptions\Domain\ReviewPeriodClosed;
use App\Http\Requests\Reviews\SubmitReviewRequest;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Review;
use App\Models\ReviewAnswer;
use App\Models\ReviewAssignment;
use App\Models\ReviewRevision;
use App\Models\Rubric;
use App\Models\Submission;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Reviews\PublishedRubrics;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Guarda la evaluación de un evaluador sobre una propuesta asignada (plan 011, H-011-3, CA-011-13 a 15).
 * Se ejecuta en la organización DE LA ASIGNACIÓN. Todo ocurre en una transacción con la asignación
 * bloqueada: si algo falla no queda ninguna respuesta parcial.
 *
 * - Envío (`$asDraft = false`): valida cada respuesta contra los criterios de la rúbrica PUBLICADA vigente
 *   del tipo de la propuesta (los de calificación son obligatorios y van dentro de su escala), guarda la
 *   evaluación con esa versión, suma uno a `revision` y deja la asignación en `submitted`. Reevaluar (ya
 *   estaba enviada) conserva antes la versión anterior en `review_history`; los resultados usan siempre la
 *   evaluación vigente.
 * - Borrador (`$asDraft = true`): guarda lo que haya (cada valor presente, validado) y deja la asignación
 *   en `in_progress`; no cambia `revision`. No se permite sobre una asignación ya enviada: se reevalúa.
 *
 * Con el periodo del evento cerrado ninguna de las dos se permite (error visible, CA-011-26). Queda
 * auditado sin el texto de los comentarios.
 *
 * @param  array<array-key, mixed>  $answers  valor de cada criterio, con el identificador del criterio como clave
 *
 * @throws AuthorizationException si la asignación no es del usuario, está cancelada o en conflicto, o falta su asignación de evento
 * @throws ReviewPeriodClosed si el periodo de evaluación del evento está cerrado
 * @throws NoPublishedRubric si el tipo de la propuesta no tiene rúbrica publicada
 * @throws ValidationException si una respuesta no cumple su criterio, falta una obligatoria o se pide un borrador sobre una enviada
 */
final class SubmitReview
{
    use AuthorizesInOrganization;

    /** @param  array<array-key, mixed>  $answers */
    public function handle(User $actor, ReviewAssignment $assignment, array $answers, bool $asDraft = false): Review
    {
        $organization = Organization::query()->findOrFail($assignment->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $assignment, $answers, $asDraft): Review {
            $this->authorizeActor($actor, 'submit', Review::class, $assignment);

            return DB::transaction(function () use ($actor, $assignment, $answers, $asDraft): Review {
                $current = ReviewAssignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();

                // El estado puede haber cambiado desde que se cargó la pantalla (cancelada, conflicto).
                $this->authorizeActor($actor, 'submit', Review::class, $current);

                $event = Event::query()->findOrFail($current->event_id);

                if (! $event->reviewPeriodIsOpen()) {
                    throw new ReviewPeriodClosed;
                }

                if ($asDraft && $current->status === ReviewAssignmentStatus::Submitted) {
                    throw ValidationException::withMessages(['answers' => __('reviewer.draft_after_submit')]);
                }

                $submission = Submission::query()->findOrFail($current->submission_id);
                $rubric = PublishedRubrics::latestFor($event->id, $submission->submission_type_id) ?? throw new NoPublishedRubric;
                $values = $this->normalized($answers);

                // El mensaje de cada error nombra al criterio por su etiqueta, no por su identificador.
                $labels = $rubric->criteria->mapWithKeys(static fn ($criterion): array => ["answers.{$criterion->id}" => $criterion->labelFor(app()->getLocale())])->all();

                Validator::make(['answers' => $values], SubmitReviewRequest::rulesFor($rubric->criteria, $asDraft), [], $labels)->validate();

                $review = Review::query()->where('review_assignment_id', $current->id)->lockForUpdate()->first();
                $wasSubmitted = $review !== null && $review->revision >= 1;

                if ($review !== null && $wasSubmitted && ! $asDraft) {
                    $this->keepPreviousVersion($review);
                }

                $review ??= (new Review)->forceFill(['revision' => 0, 'submitted_at' => null]);
                $review->forceFill([
                    'review_assignment_id' => $current->id,
                    'rubric_id' => $rubric->id,
                    ...($asDraft ? [] : ['submitted_at' => Carbon::now('UTC'), 'revision' => $review->revision + 1]),
                ])->save();

                $this->replaceAnswers($review, $rubric, $values);

                $previous = $current->status;
                $current->forceFill(['status' => $asDraft ? ReviewAssignmentStatus::InProgress : ReviewAssignmentStatus::Submitted])->save();

                $this->audit($actor, $review, $current, $rubric, $previous, $asDraft, $wasSubmitted);

                return $review;
            });
        });
    }

    /**
     * Texto vacío es «sin respuesta» y los espacios de los extremos no cuentan; el resto no se toca.
     *
     * @param  array<array-key, mixed>  $answers
     * @return array<string, mixed>
     */
    private function normalized(array $answers): array
    {
        $values = [];

        foreach ($answers as $criterionId => $value) {
            $value = is_string($value) ? trim($value) : $value;
            $values[(string) $criterionId] = $value === '' ? null : $value;
        }

        return $values;
    }

    /** Conserva la versión que se va a reemplazar (CA-011-15): su número de envío, su rúbrica y sus respuestas. */
    private function keepPreviousVersion(Review $review): void
    {
        $revision = new ReviewRevision;
        $revision->forceFill([
            'review_id' => $review->id,
            'rubric_id' => $review->rubric_id,
            'revision' => $review->revision,
            'submitted_at' => $review->submitted_at,
            'answers' => $review->answers()->get()->map(static fn (ReviewAnswer $answer): array => [
                'rubric_criterion_id' => $answer->rubric_criterion_id,
                'value_int' => $answer->value_int,
                'value_text' => $answer->value_text,
            ])->values()->all(),
        ])->save();
    }

    /**
     * Las respuestas de la evaluación son exactamente las que llegaron (un criterio sin valor no tiene fila).
     *
     * @param  array<string, mixed>  $values
     */
    private function replaceAnswers(Review $review, Rubric $rubric, array $values): void
    {
        $review->answers()->delete();

        foreach ($rubric->criteria as $criterion) {
            $value = $values[$criterion->id] ?? null;

            if ($value === null) {
                continue;
            }

            $answer = new ReviewAnswer;
            $answer->forceFill([
                'review_id' => $review->id,
                'rubric_criterion_id' => $criterion->id,
                'value_int' => $criterion->kind === CriterionKind::Scale ? (int) $value : null,
                'value_text' => $criterion->kind === CriterionKind::Text ? (string) $value : null,
            ])->save();
        }
    }

    private function audit(User $actor, Review $review, ReviewAssignment $assignment, Rubric $rubric, ReviewAssignmentStatus $previous, bool $asDraft, bool $wasSubmitted): void
    {
        $event = match (true) {
            $asDraft => 'review_draft_saved',
            $wasSubmitted => 'review_resubmitted',
            default => 'review_submitted',
        };

        activity()
            ->causedBy($actor)
            ->performedOn($review)
            ->event($event)
            ->withProperties([
                'organization_id' => $assignment->organization_id,
                'event_id' => $assignment->event_id,
                'submission_id' => $assignment->submission_id,
                'rubric_id' => $rubric->id,
                'rubric_version' => $rubric->version,
                'revision' => $review->revision,
                'old' => ['status' => $previous->value],
                'new' => ['status' => $assignment->status->value],
            ])
            ->log($event);
    }
}
