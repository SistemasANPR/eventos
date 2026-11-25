<?php

declare(strict_types=1);

use App\Actions\Reviews\SubmitReview;
use App\Enums\OrganizationRole;
use App\Enums\ReviewAssignmentStatus;
use App\Exceptions\Domain\NoPublishedRubric;
use App\Exceptions\Domain\ReviewPeriodClosed;
use App\Models\Event;
use App\Models\EventAssignment;
use App\Models\Organization;
use App\Models\Review;
use App\Models\ReviewAnswer;
use App\Models\ReviewAssignment;
use App\Models\ReviewRevision;
use App\Models\Rubric;
use App\Models\RubricCriterion;
use App\Models\Submission;
use App\Models\User;
use App\Support\Reviews\RecordedReviews;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

/*
 * Plan 011, F4: `SubmitReview`. Cada prueba usa un solo rol por organización: la caché de permisos de
 * Spatie se comparte dentro del proceso.
 */

afterEach(function (): void {
    CurrentOrganization::forget();
});

/**
 * Una organización con un evento, una propuesta, una rúbrica publicada del tipo de la propuesta (dos criterios de
 * calificación, 1 a 5 y 1 a 10, y un comentario) y un evaluador con su asignación pendiente.
 *
 * @return array{organization: Organization, event: Event, submission: Submission, rubric: Rubric, scale: RubricCriterion, wide: RubricCriterion, comment: RubricCriterion, reviewer: User, assignment: ReviewAssignment}
 */
function srScenario(): array
{
    $organization = Memberships::organization();
    $event = Event::factory()->create(['organization_id' => $organization->id]);
    $submission = Submission::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id]);
    $rubric = Rubric::factory()->published()->create([
        'organization_id' => $organization->id,
        'event_id' => $event->id,
        'submission_type_id' => $submission->submission_type_id,
    ]);
    $criterion = fn (int $position, array $attributes = []) => RubricCriterion::factory()->create([
        'organization_id' => $organization->id,
        'rubric_id' => $rubric->id,
        'position' => $position,
        ...$attributes,
    ]);
    $scale = $criterion(1, ['label' => ['es' => 'Relevancia']]);
    $wide = $criterion(2, ['label' => ['es' => 'Claridad'], 'scale_min' => 1, 'scale_max' => 10, 'weight' => 2]);
    $comment = $criterion(3, ['label' => ['es' => 'Comentario'], 'kind' => 'text']);

    $reviewer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);
    EventAssignment::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id, 'user_id' => $reviewer->id]);
    $assignment = ReviewAssignment::factory()->create([
        'organization_id' => $organization->id,
        'event_id' => $event->id,
        'submission_id' => $submission->id,
        'reviewer_id' => $reviewer->id,
    ]);

    return compact('organization', 'event', 'submission', 'rubric', 'scale', 'wide', 'comment', 'reviewer', 'assignment');
}

/**
 * @param  array<string, mixed>  $scenario
 * @return array<string, int|string> respuestas válidas por criterio
 */
function srAnswers(array $scenario, int $scale = 4, int $wide = 8, string $comment = 'Buena propuesta'): array
{
    return [$scenario['scale']->id => $scale, $scenario['wide']->id => $wide, $scenario['comment']->id => $comment];
}

/** @param  array<string, mixed>  $scenario */
function srReviewOf(array $scenario): ?Review
{
    return CurrentOrganization::run($scenario['organization'], fn (): ?Review => Review::query()->where('review_assignment_id', $scenario['assignment']->id)->first());
}

it('CA-011-13: a valid submission stores the review and its answers and marks the assignment submitted', function (): void {
    $scenario = srScenario();

    $review = app(SubmitReview::class)->handle($scenario['reviewer'], $scenario['assignment'], srAnswers($scenario));

    CurrentOrganization::run($scenario['organization'], function () use ($scenario, $review): void {
        expect($review->revision)->toBe(1)
            ->and($review->submitted_at)->not->toBeNull()
            ->and($review->rubric_id)->toBe($scenario['rubric']->id)
            ->and(ReviewAnswer::query()->where('review_id', $review->id)->count())->toBe(3)
            ->and(ReviewAnswer::query()->where('rubric_criterion_id', $scenario['wide']->id)->value('value_int'))->toBe(8)
            ->and(ReviewAnswer::query()->where('rubric_criterion_id', $scenario['comment']->id)->value('value_text'))->toBe('Buena propuesta')
            ->and(ReviewAssignment::query()->findOrFail($scenario['assignment']->id)->status)->toBe(ReviewAssignmentStatus::Submitted);
    });
});

it('CA-011-13: the submission is audited without the text of the comments', function (): void {
    $scenario = srScenario();

    app(SubmitReview::class)->handle($scenario['reviewer'], $scenario['assignment'], srAnswers($scenario, comment: 'Texto privado del evaluador'));

    $log = Activity::query()->where('event', 'review_submitted')->sole();

    expect($log->causer_id)->toBe($scenario['reviewer']->id)
        ->and($log->properties->get('revision'))->toBe(1)
        ->and(json_encode($log->properties))->not->toContain('Texto privado del evaluador');
});

it('CA-011-14: a value outside the scale is rejected and leaves no partial data', function (): void {
    $scenario = srScenario();

    expect(fn () => app(SubmitReview::class)->handle($scenario['reviewer'], $scenario['assignment'], srAnswers($scenario, scale: 6)))
        ->toThrow(ValidationException::class);

    // Cada criterio con su escala: 8 vale para el de 1 a 10 y no para el de 1 a 5.
    expect(fn () => app(SubmitReview::class)->handle($scenario['reviewer'], $scenario['assignment'], srAnswers($scenario, scale: 8)))
        ->toThrow(ValidationException::class)
        ->and(srReviewOf($scenario))->toBeNull();

    CurrentOrganization::run($scenario['organization'], function () use ($scenario): void {
        expect(ReviewAnswer::query()->count())->toBe(0)
            ->and(ReviewAssignment::query()->findOrFail($scenario['assignment']->id)->status)->toBe(ReviewAssignmentStatus::Pending);
    });
});

it('CA-011-14: an empty required criterion is rejected and leaves no partial data', function (): void {
    $scenario = srScenario();
    $answers = srAnswers($scenario);
    $answers[$scenario['wide']->id] = '';

    try {
        app(SubmitReview::class)->handle($scenario['reviewer'], $scenario['assignment'], $answers);
        $this->fail('Debía rechazar el criterio obligatorio vacío.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['answers.'.$scenario['wide']->id]);
    }

    expect(srReviewOf($scenario))->toBeNull();
});

it('CA-011-14: a non-integer value, an unknown criterion and an over-long comment are rejected', function (string $label, callable $tamper): void {
    $scenario = srScenario();
    $answers = $tamper(srAnswers($scenario), $scenario);

    expect(fn () => app(SubmitReview::class)->handle($scenario['reviewer'], $scenario['assignment'], $answers))
        ->toThrow(ValidationException::class);
    expect(srReviewOf($scenario))->toBeNull();
})->with([
    'decimal' => ['decimal', fn (array $answers, array $scenario): array => [...$answers, $scenario['scale']->id => '3.5']],
    'text instead of a number' => ['text', fn (array $answers, array $scenario): array => [...$answers, $scenario['scale']->id => 'cuatro']],
    'unknown criterion' => ['unknown', fn (array $answers): array => [...$answers, '00000000-0000-4000-8000-000000000000' => 3]],
    'comment too long' => ['long', fn (array $answers, array $scenario): array => [...$answers, $scenario['comment']->id => str_repeat('a', 5001)]],
]);

it('CA-011-14: a failure while writing rolls everything back', function (): void {
    $scenario = srScenario();
    $answers = srAnswers($scenario);

    // Se simula la falla a mitad de la escritura: la base rechaza la segunda fila de respuesta (8), cuando la
    // evaluación y la primera respuesta ya se escribieron.
    DB::statement('ALTER TABLE review_answers ADD CONSTRAINT srt_fail CHECK (value_int IS NULL OR value_int < 8)');

    try {
        expect(fn () => app(SubmitReview::class)->handle($scenario['reviewer'], $scenario['assignment'], $answers))
            ->toThrow(QueryException::class);
    } finally {
        DB::statement('ALTER TABLE review_answers DROP CONSTRAINT srt_fail');
    }

    expect(srReviewOf($scenario))->toBeNull();
    CurrentOrganization::run($scenario['organization'], function () use ($scenario): void {
        expect(ReviewAssignment::query()->findOrFail($scenario['assignment']->id)->status)->toBe(ReviewAssignmentStatus::Pending);
    });
});

it('CA-011-15: resubmitting while the period is open increments the revision and keeps the previous version', function (): void {
    $scenario = srScenario();
    $action = app(SubmitReview::class);
    $action->handle($scenario['reviewer'], $scenario['assignment'], srAnswers($scenario, scale: 2, wide: 3, comment: 'Primera'));

    $review = $action->handle($scenario['reviewer'], $scenario['assignment']->refresh(), srAnswers($scenario, scale: 5, wide: 9, comment: 'Segunda'));

    CurrentOrganization::run($scenario['organization'], function () use ($scenario, $review): void {
        $history = ReviewRevision::query()->where('review_id', $review->id)->get();

        expect($review->revision)->toBe(2)
            ->and(Review::query()->where('review_assignment_id', $scenario['assignment']->id)->count())->toBe(1)
            ->and($history)->toHaveCount(1)
            ->and($history->first()->revision)->toBe(1)
            ->and($history->first()->rubric_id)->toBe($scenario['rubric']->id)
            ->and(collect($history->first()->answers)->firstWhere('rubric_criterion_id', $scenario['scale']->id)['value_int'])->toBe(2)
            ->and(collect($history->first()->answers)->firstWhere('rubric_criterion_id', $scenario['comment']->id)['value_text'])->toBe('Primera')
            // Los resultados leen la evaluación vigente: sus respuestas son las de la última versión.
            ->and(ReviewAnswer::query()->where('review_id', $review->id)->count())->toBe(3)
            ->and(ReviewAnswer::query()->where('rubric_criterion_id', $scenario['scale']->id)->value('value_int'))->toBe(5)
            ->and(ReviewAssignment::query()->findOrFail($scenario['assignment']->id)->status)->toBe(ReviewAssignmentStatus::Submitted);
    });

    expect(Activity::query()->where('event', 'review_resubmitted')->count())->toBe(1);
});

it('CA-011-15: a new published rubric version is used on resubmission and the old version stays in the history', function (): void {
    $scenario = srScenario();
    $action = app(SubmitReview::class);
    $action->handle($scenario['reviewer'], $scenario['assignment'], srAnswers($scenario));

    $second = Rubric::factory()->published()->create([
        'organization_id' => $scenario['organization']->id,
        'event_id' => $scenario['event']->id,
        'submission_type_id' => $scenario['submission']->submission_type_id,
        'version' => 2,
    ]);
    $criterion = RubricCriterion::factory()->create(['organization_id' => $scenario['organization']->id, 'rubric_id' => $second->id, 'scale_min' => 1, 'scale_max' => 3]);

    $review = $action->handle($scenario['reviewer'], $scenario['assignment']->refresh(), [$criterion->id => 3]);

    CurrentOrganization::run($scenario['organization'], function () use ($review, $second, $scenario): void {
        expect($review->rubric_id)->toBe($second->id)
            ->and(ReviewRevision::query()->where('review_id', $review->id)->sole()->rubric_id)->toBe($scenario['rubric']->id);
    });
});

it('CA-011-26: with the period closed the submission is rejected with a visible error and changes nothing', function (): void {
    $scenario = srScenario();
    $scenario['event']->forceFill(['review_period_closed_at' => now()])->save();

    expect(fn () => app(SubmitReview::class)->handle($scenario['reviewer'], $scenario['assignment'], srAnswers($scenario)))
        ->toThrow(ReviewPeriodClosed::class, ReviewPeriodClosed::CODE)
        ->and(fn () => app(SubmitReview::class)->handle($scenario['reviewer'], $scenario['assignment'], [], asDraft: true))
        ->toThrow(ReviewPeriodClosed::class);

    expect(srReviewOf($scenario))->toBeNull();
});

it('CA-011-15: a closed period also blocks a resubmission and keeps the submitted review', function (): void {
    $scenario = srScenario();
    app(SubmitReview::class)->handle($scenario['reviewer'], $scenario['assignment'], srAnswers($scenario));
    $scenario['event']->forceFill(['review_period_closed_at' => now()])->save();

    expect(fn () => app(SubmitReview::class)->handle($scenario['reviewer'], $scenario['assignment']->refresh(), srAnswers($scenario, scale: 1)))
        ->toThrow(ReviewPeriodClosed::class);

    expect(srReviewOf($scenario)?->revision)->toBe(1);
});

it('CA-011-13: a draft saves what there is, marks the assignment in progress and does not count as a submission', function (): void {
    $scenario = srScenario();

    $review = app(SubmitReview::class)->handle($scenario['reviewer'], $scenario['assignment'], [$scenario['scale']->id => 3, $scenario['wide']->id => ''], asDraft: true);

    CurrentOrganization::run($scenario['organization'], function () use ($scenario, $review): void {
        expect($review->revision)->toBe(0)
            ->and($review->submitted_at)->toBeNull()
            ->and(ReviewAnswer::query()->where('review_id', $review->id)->count())->toBe(1)
            ->and(ReviewAssignment::query()->findOrFail($scenario['assignment']->id)->status)->toBe(ReviewAssignmentStatus::InProgress);
    });

    // Sigue siendo un borrador al guardarlo otra vez y se completa al enviarlo.
    app(SubmitReview::class)->handle($scenario['reviewer'], $scenario['assignment']->refresh(), [$scenario['scale']->id => 4], asDraft: true);
    $final = app(SubmitReview::class)->handle($scenario['reviewer'], $scenario['assignment']->refresh(), srAnswers($scenario));

    expect($final->id)->toBe($review->id)->and($final->revision)->toBe(1);
    CurrentOrganization::run($scenario['organization'], fn () => expect(ReviewRevision::query()->count())->toBe(0));
});

it('CA-011-13: a draft over a submitted review is rejected so it cannot overwrite it', function (): void {
    $scenario = srScenario();
    app(SubmitReview::class)->handle($scenario['reviewer'], $scenario['assignment'], srAnswers($scenario));

    expect(fn () => app(SubmitReview::class)->handle($scenario['reviewer'], $scenario['assignment']->refresh(), [$scenario['scale']->id => 1], asDraft: true))
        ->toThrow(ValidationException::class);
});

it('CA-011-13: a draft validates every present value against its scale', function (): void {
    $scenario = srScenario();

    expect(fn () => app(SubmitReview::class)->handle($scenario['reviewer'], $scenario['assignment'], [$scenario['scale']->id => 9], asDraft: true))
        ->toThrow(ValidationException::class);
    expect(srReviewOf($scenario))->toBeNull();
});

it('CA-011-13: a type without a published rubric cannot be reviewed', function (): void {
    $scenario = srScenario();
    CurrentOrganization::run($scenario['organization'], fn () => Rubric::query()->whereKey($scenario['rubric']->id)->update(['status' => 'draft']));

    expect(fn () => app(SubmitReview::class)->handle($scenario['reviewer'], $scenario['assignment'], srAnswers($scenario)))
        ->toThrow(NoPublishedRubric::class);
});

it('CA-011-12: only the reviewer of the assignment can submit it', function (): void {
    $scenario = srScenario();
    $other = Memberships::attach(User::factory()->create(), $scenario['organization'], OrganizationRole::Reviewer);
    EventAssignment::factory()->create(['organization_id' => $scenario['organization']->id, 'event_id' => $scenario['event']->id, 'user_id' => $other->id]);
    $organizer = Memberships::attach(User::factory()->create(), $scenario['organization'], OrganizationRole::OrgAdmin);

    expect(fn () => app(SubmitReview::class)->handle($other, $scenario['assignment'], srAnswers($scenario)))
        ->toThrow(AuthorizationException::class)
        // Ni siquiera un administrador califica en nombre de otro.
        ->and(fn () => app(SubmitReview::class)->handle($organizer, $scenario['assignment'], srAnswers($scenario)))
        ->toThrow(AuthorizationException::class);

    expect(srReviewOf($scenario))->toBeNull();
});

it('CA-011-28: a reviewer without the event assignment, or with the assignment of another event, cannot submit', function (): void {
    $scenario = srScenario();
    CurrentOrganization::run($scenario['organization'], fn () => EventAssignment::query()->where('user_id', $scenario['reviewer']->id)->delete());

    expect(fn () => app(SubmitReview::class)->handle($scenario['reviewer'], $scenario['assignment'], srAnswers($scenario)))
        ->toThrow(AuthorizationException::class);

    $otherEvent = Event::factory()->create(['organization_id' => $scenario['organization']->id]);
    EventAssignment::factory()->create(['organization_id' => $scenario['organization']->id, 'event_id' => $otherEvent->id, 'user_id' => $scenario['reviewer']->id]);

    expect(fn () => app(SubmitReview::class)->handle($scenario['reviewer'], $scenario['assignment'], srAnswers($scenario)))
        ->toThrow(AuthorizationException::class);
});

it('CA-011-53: a cancelled assignment and one with a declared conflict cannot be graded', function (ReviewAssignmentStatus $status): void {
    $scenario = srScenario();
    CurrentOrganization::run($scenario['organization'], fn () => ReviewAssignment::query()->whereKey($scenario['assignment']->id)->update(['status' => $status]));

    expect(fn () => app(SubmitReview::class)->handle($scenario['reviewer'], $scenario['assignment']->refresh(), srAnswers($scenario)))
        ->toThrow(AuthorizationException::class);
    expect(srReviewOf($scenario))->toBeNull();
})->with([ReviewAssignmentStatus::Cancelled, ReviewAssignmentStatus::DeclinedConflict]);

it('CA-011-12: the review policy is denied without organization context and for a deactivated member', function (): void {
    $scenario = srScenario();
    CurrentOrganization::forget();

    expect(Gate::forUser($scenario['reviewer'])->allows('submit', [Review::class, $scenario['assignment']]))->toBeFalse();

    $scenario['reviewer']->organizations()->updateExistingPivot($scenario['organization']->id, ['status' => 'deactivated', 'deactivated_at' => now()]);

    expect(fn () => app(SubmitReview::class)->handle($scenario['reviewer']->refresh(), $scenario['assignment'], srAnswers($scenario)))
        ->toThrow(AuthorizationException::class);
});

it('CA-011-3: a rubric version with a review, even a draft, counts as having recorded reviews', function (): void {
    $scenario = srScenario();
    $checker = new RecordedReviews;

    CurrentOrganization::run($scenario['organization'], fn () => expect($checker->hasRecordedReviews($scenario['rubric']))->toBeFalse());

    app(SubmitReview::class)->handle($scenario['reviewer'], $scenario['assignment'], [$scenario['scale']->id => 3], asDraft: true);

    CurrentOrganization::run($scenario['organization'], fn () => expect($checker->hasRecordedReviews($scenario['rubric']))->toBeTrue());
});
