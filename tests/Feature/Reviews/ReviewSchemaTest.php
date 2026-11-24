<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Organization;
use App\Models\Review;
use App\Models\ReviewAnswer;
use App\Models\ReviewAssignment;
use App\Models\ReviewRevision;
use App\Models\Rubric;
use App\Models\RubricCriterion;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Tenancy\Exceptions\MissingOrganizationContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * Plan 011, F4: lo que la base de `reviews`, `review_answers` y `review_history` garantiza por sí misma
 * (propuesta de datos §4.2 y 3): una evaluación por asignación, organización compartida por clave compuesta,
 * una respuesta entera o de texto, y el aislamiento entre organizaciones de los modelos.
 */

afterEach(function (): void {
    CurrentOrganization::forget();
});

it('the database rejects a second review of the same assignment', function (): void {
    $review = Review::factory()->create();

    expect(fn () => DB::table('reviews')->insert([
        'id' => (string) Str::uuid(), 'organization_id' => $review->organization_id,
        'review_assignment_id' => $review->review_assignment_id, 'rubric_id' => $review->rubric_id,
        'revision' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('the database rejects a review whose assignment or rubric belongs to another organization', function (): void {
    $organization = Organization::factory()->create();
    $foreignAssignment = ReviewAssignment::factory()->create();
    $foreignRubric = Rubric::factory()->published()->create();
    $ownRubric = Rubric::factory()->published()->create(['organization_id' => $organization->id]);
    $ownAssignment = ReviewAssignment::factory()->create(['organization_id' => $organization->id]);

    foreach ([[$foreignAssignment->id, $ownRubric->id], [$ownAssignment->id, $foreignRubric->id]] as [$assignmentId, $rubricId]) {
        expect(fn () => DB::transaction(fn () => DB::table('reviews')->insert([
            'id' => (string) Str::uuid(), 'organization_id' => $organization->id,
            'review_assignment_id' => $assignmentId, 'rubric_id' => $rubricId,
            'revision' => 0, 'created_at' => now(), 'updated_at' => now(),
        ])))->toThrow(QueryException::class);
    }
});

it('the database accepts an answer that is either an integer or a text and rejects both or neither', function (): void {
    $review = Review::factory()->create();
    $criterion = RubricCriterion::factory()->create(['organization_id' => $review->organization_id, 'rubric_id' => $review->rubric_id, 'position' => 1]);
    $textCriterion = RubricCriterion::factory()->text()->create(['organization_id' => $review->organization_id, 'rubric_id' => $review->rubric_id, 'position' => 2]);
    $row = fn (string $criterionId, ?int $int, ?string $text): array => [
        'id' => (string) Str::uuid(), 'organization_id' => $review->organization_id, 'review_id' => $review->id,
        'rubric_criterion_id' => $criterionId, 'value_int' => $int, 'value_text' => $text,
        'created_at' => now(), 'updated_at' => now(),
    ];

    expect(DB::table('review_answers')->insert($row($criterion->id, 4, null)))->toBeTrue()
        ->and(DB::table('review_answers')->insert($row($textCriterion->id, null, 'Comentario')))->toBeTrue();

    foreach ([[3, 'texto'], [null, null]] as [$int, $text]) {
        $other = RubricCriterion::factory()->create(['organization_id' => $review->organization_id, 'rubric_id' => $review->rubric_id, 'position' => 10 + (int) $int]);

        expect(fn () => DB::transaction(fn () => DB::table('review_answers')->insert($row($other->id, $int, $text))))
            ->toThrow(QueryException::class);
    }

    // Una sola respuesta por criterio y evaluación.
    expect(fn () => DB::transaction(fn () => DB::table('review_answers')->insert($row($criterion->id, 2, null))))
        ->toThrow(QueryException::class);
});

it('the database rejects an answer to a criterion of another organization', function (): void {
    $review = Review::factory()->create();
    $foreign = RubricCriterion::factory()->create();

    expect(fn () => DB::transaction(fn () => DB::table('review_answers')->insert([
        'id' => (string) Str::uuid(), 'organization_id' => $review->organization_id, 'review_id' => $review->id,
        'rubric_criterion_id' => $foreign->id, 'value_int' => 3, 'value_text' => null,
        'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(QueryException::class);
});

it('the history keeps one row per revision of a review', function (): void {
    $review = Review::factory()->create();
    ReviewRevision::factory()->create(['organization_id' => $review->organization_id, 'review_id' => $review->id, 'revision' => 1]);

    expect(fn () => DB::transaction(fn () => ReviewRevision::factory()->create(['organization_id' => $review->organization_id, 'review_id' => $review->id, 'revision' => 1])))
        ->toThrow(QueryException::class);
});

it('the database refuses to move a review, an answer or a history row to another organization', function (): void {
    $review = Review::factory()->create();
    $answer = ReviewAnswer::factory()->create(['organization_id' => $review->organization_id, 'review_id' => $review->id]);
    $revision = ReviewRevision::factory()->create(['organization_id' => $review->organization_id, 'review_id' => $review->id]);
    $other = Organization::factory()->create();

    foreach ([['reviews', $review->id], ['review_answers', $answer->id], ['review_history', $revision->id]] as [$table, $id]) {
        expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $id)->update(['organization_id' => $other->id])))
            ->toThrow(QueryException::class, 'organization_id');
    }
});

it('an event is created with its review period open', function (): void {
    $event = Event::factory()->create();

    expect($event->review_period_closed_at)->toBeNull()
        ->and($event->reviewPeriodIsOpen())->toBeTrue();

    $event->forceFill(['review_period_closed_at' => now()])->save();

    expect($event->fresh()?->reviewPeriodIsOpen())->toBeFalse();
});

it('one organization never sees the reviews of another and a query without context fails', function (string $model): void {
    $mine = $model::factory()->create();
    $theirs = $model::factory()->create();

    CurrentOrganization::run(Organization::query()->findOrFail($mine->organization_id), function () use ($model, $mine, $theirs): void {
        expect($model::query()->pluck('id')->all())->toBe([$mine->id])
            ->and($model::query()->find($theirs->id))->toBeNull();
    });

    expect(fn () => $model::query()->count())->toThrow(MissingOrganizationContext::class);
})->with([Review::class, ReviewAnswer::class, ReviewRevision::class]);
