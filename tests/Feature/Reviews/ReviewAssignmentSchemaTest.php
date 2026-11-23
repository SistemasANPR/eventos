<?php

declare(strict_types=1);

use App\Enums\ReviewAssignmentStatus;
use App\Models\Event;
use App\Models\Organization;
use App\Models\ReviewAssignment;
use App\Models\Submission;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Tenancy\Exceptions\MissingOrganizationContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * Plan 011, F3a: lo que la base de `review_assignments` garantiza por sí misma (propuesta de datos §4.2 y 3):
 * unicidad por propuesta y evaluador, organización compartida por clave compuesta, estados y origen cerrados
 * e inmutabilidad de la organización. Más el aislamiento entre organizaciones del modelo.
 */

afterEach(function (): void {
    CurrentOrganization::forget();
});

/**
 * Inserta una fila directa para probar las restricciones de la base sin pasar por el modelo.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function raRow(string $organizationId, string $eventId, string $submissionId, string $reviewerId, array $overrides = []): array
{
    return [
        'id' => (string) Str::uuid(), 'organization_id' => $organizationId, 'event_id' => $eventId,
        'submission_id' => $submissionId, 'reviewer_id' => $reviewerId, 'status' => 'pending',
        'assigned_at' => now(), 'source' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ...$overrides,
    ];
}

it('the database rejects a second assignment of the same reviewer to the same submission', function (): void {
    $assignment = ReviewAssignment::factory()->create();

    expect(fn () => DB::table('review_assignments')->insert(raRow(
        $assignment->organization_id, $assignment->event_id, $assignment->submission_id, $assignment->reviewer_id,
    )))->toThrow(QueryException::class);
});

it('the database rejects a reviewer who is not a member of the organization of the assignment', function (): void {
    $organization = Organization::factory()->create();
    $event = Event::factory()->create(['organization_id' => $organization->id]);
    $submission = Submission::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id]);
    $outsider = User::factory()->create();

    expect(fn () => DB::table('review_assignments')->insert(raRow($organization->id, $event->id, $submission->id, $outsider->id)))
        ->toThrow(QueryException::class);
});

it('the database rejects a submission that does not belong to the event or the organization', function (): void {
    $organization = Organization::factory()->create();
    $event = Event::factory()->create(['organization_id' => $organization->id]);
    $otherEvent = Event::factory()->create(['organization_id' => $organization->id]);
    $submission = Submission::factory()->create(['organization_id' => $organization->id, 'event_id' => $otherEvent->id]);
    $foreign = Submission::factory()->create();
    $reviewer = User::factory()->create();
    $reviewer->organizations()->attach($organization->id, ['status' => 'active']);

    foreach ([$submission, $foreign] as $mismatched) {
        expect(fn () => DB::transaction(fn () => DB::table('review_assignments')->insert(
            raRow($organization->id, $event->id, $mismatched->id, $reviewer->id),
        )))->toThrow(QueryException::class);
    }
});

it('the database only accepts the five statuses and the two sources', function (): void {
    $assignment = ReviewAssignment::factory()->create();

    foreach (ReviewAssignmentStatus::cases() as $status) {
        expect(DB::table('review_assignments')->where('id', $assignment->id)->update(['status' => $status->value]))->toBe(1);
    }

    foreach (['status' => 'done', 'source' => 'import'] as $column => $value) {
        expect(fn () => DB::transaction(fn () => DB::table('review_assignments')->where('id', $assignment->id)->update([$column => $value])))
            ->toThrow(QueryException::class);
    }
});

it('the database refuses to move an assignment to another organization', function (): void {
    $assignment = ReviewAssignment::factory()->create();
    $other = Organization::factory()->create();

    expect(fn () => DB::transaction(fn () => DB::table('review_assignments')->where('id', $assignment->id)->update(['organization_id' => $other->id])))
        ->toThrow(QueryException::class, 'organization_id');
});

it('one organization never sees the assignments of another and a query without context fails', function (): void {
    $mine = ReviewAssignment::factory()->create();
    $theirs = ReviewAssignment::factory()->create();

    CurrentOrganization::run(Organization::query()->findOrFail($mine->organization_id), function () use ($mine, $theirs): void {
        expect(ReviewAssignment::query()->pluck('id')->all())->toBe([$mine->id])
            ->and(ReviewAssignment::query()->find($theirs->id))->toBeNull();
    });

    expect(fn () => ReviewAssignment::query()->count())->toThrow(MissingOrganizationContext::class);
});
