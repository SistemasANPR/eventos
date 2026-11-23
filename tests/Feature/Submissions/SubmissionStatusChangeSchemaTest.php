<?php

declare(strict_types=1);

use App\Enums\SubmissionStatus;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\SubmissionStatusChange;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
});

/*
 * Integridad del historial de estados (plan 012, §4 propuesta de datos): la base misma impide un cambio
 * que cruce organizaciones o eventos y los valores fuera de la tabla de estados.
 */

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed> fila válida de `submission_status_changes` para la propuesta
 */
function statusChangeRow(Submission $submission, array $overrides = []): array
{
    return [
        'id' => (string) Str::uuid(),
        'organization_id' => $submission->organization_id,
        'event_id' => $submission->event_id,
        'submission_id' => $submission->id,
        'from_status' => 'submitted',
        'to_status' => 'under_review',
        'created_at' => now(),
        ...$overrides,
    ];
}

it('CA-012-1: the database refuses a status change that points to a submission of another organization or event', function (): void {
    $organization = Organization::factory()->create();
    $other = Organization::factory()->create();
    $submission = CurrentOrganization::run($organization, fn (): Submission => Submission::factory()->create());
    $otherEvent = Event::factory()->create(['organization_id' => $other->id]);
    $sameOrgEvent = Event::factory()->create(['organization_id' => $organization->id]);

    DB::table('submission_status_changes')->insert(statusChangeRow($submission));

    expect(fn () => DB::table('submission_status_changes')->insert(statusChangeRow($submission, ['organization_id' => $other->id, 'event_id' => $otherEvent->id])))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('submission_status_changes')->insert(statusChangeRow($submission, ['event_id' => $sameOrgEvent->id])))
        ->toThrow(QueryException::class);
});

it('CA-012-1: the database refuses unknown states and a change to the same state', function (array $override): void {
    $submission = Submission::factory()->create();

    expect(fn () => DB::table('submission_status_changes')->insert(statusChangeRow($submission, $override)))
        ->toThrow(QueryException::class);
})->with([
    'unknown from' => [['from_status' => 'maybe']],
    'unknown to' => [['to_status' => 'maybe']],
    'same state' => [['from_status' => 'accepted', 'to_status' => 'accepted']],
    'comment too long' => [['author_comment' => str_repeat('a', 1001)]],
]);

it('CA-012-29: the database refuses an author comment over the maximum on the submission', function (): void {
    $submission = Submission::factory()->create();

    expect(fn () => DB::table('submissions')->where('id', $submission->id)->update(['author_comment' => str_repeat('a', 1001)]))
        ->toThrow(QueryException::class);
});

it('CA-012-1: the history of a submission is listed from the oldest to the newest', function (): void {
    $organization = Organization::factory()->create();

    CurrentOrganization::run($organization, function (): void {
        $submission = Submission::factory()->create();
        SubmissionStatusChange::factory()->create([
            'submission_id' => $submission->id,
            'from_status' => SubmissionStatus::UnderReview,
            'to_status' => SubmissionStatus::Accepted,
            'created_at' => now()->addMinute(),
        ]);
        SubmissionStatusChange::factory()->create(['submission_id' => $submission->id, 'created_at' => now()]);

        expect($submission->statusChanges->pluck('to_status')->all())->toBe([SubmissionStatus::UnderReview, SubmissionStatus::Accepted]);
    });
});
