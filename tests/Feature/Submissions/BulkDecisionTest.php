<?php

declare(strict_types=1);

use App\Actions\Submissions\AcceptSubmission;
use App\Actions\Submissions\BulkDecideSubmissions;
use App\Actions\Submissions\RevertDecisionBatch;
use App\Actions\Submissions\RevertSubmissionDecision;
use App\Enums\OrganizationRole;
use App\Enums\SubmissionStatus;
use App\Exceptions\Domain\PublishedDecisionNeedsConfirmation;
use App\Http\Requests\Submissions\BulkDecideRequest;
use App\Jobs\SendTransactionalMail;
use App\Models\CallForSubmissions;
use App\Models\DecisionBatch;
use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\SubmissionStatusChange;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\AuthorFixtures;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

/*
 * Plan 012, F2, H-012-2: decisión masiva y reversa por lote (CA-012-9 a 13).
 */

afterEach(function (): void {
    CurrentOrganization::forget();
});

/**
 * Organización con administrador, organizador y evaluador, y N propuestas del mismo evento en el estado pedido.
 *
 * @return array{0: Organization, 1: Event, 2: list<Submission>, 3: User, 4: User, 5: User}
 */
function bulkScenario(int $count = 3, SubmissionStatus $status = SubmissionStatus::UnderReview): array
{
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $organizer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer);
    $reviewer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);

    $first = AuthorFixtures::submission($organization, ['status' => $status], ['email' => 'autor0@example.test']);
    $submissions = [$first];

    for ($i = 1; $i < $count; $i++) {
        $submissions[] = AuthorFixtures::sibling($first, "autor{$i}@example.test", ['status' => $status]);
    }

    $event = CurrentOrganization::run($organization, fn (): Event => Event::query()->findOrFail($first->event_id));

    return [$organization, $event, $submissions, $admin, $organizer, $reviewer];
}

/**
 * @param  list<Submission>  $submissions
 * @return list<string>
 */
function bulkIds(array $submissions): array
{
    return array_map(static fn (Submission $submission): string => $submission->id, $submissions);
}

function bulkStatus(Organization $organization, Submission $submission): SubmissionStatus
{
    return CurrentOrganization::run($organization, fn (): SubmissionStatus => Submission::query()->findOrFail($submission->id)->status);
}

it('CA-012-9: deciding a selection moves all of it in one batch with the filter and the total audited', function (): void {
    Queue::fake();
    [$organization, $event, $submissions, , $organizer] = bulkScenario(3);

    $report = app(BulkDecideSubmissions::class)->handle($organizer, $event, [
        'to' => 'rejected',
        'submission_ids' => bulkIds($submissions),
        'author_comment' => 'Gracias <b>por</b> participar',
        'filter' => ['mode' => 'filter', 'status' => 'under_review', 'track_id' => $submissions[0]->track_id],
    ]);

    expect($report->total)->toBe(3)
        ->and($report->to)->toBe(SubmissionStatus::Rejected)
        ->and($report->byFromStatus)->toBe(['under_review' => 3])
        ->and($report->publishedNow)->toBe(0);

    CurrentOrganization::run($organization, function () use ($submissions, $report, $organizer, $event): void {
        $batch = DecisionBatch::query()->findOrFail($report->batchId);
        expect($batch->total)->toBe(3)
            ->and($batch->actor_user_id)->toBe($organizer->id)
            ->and($batch->event_id)->toBe($event->id)
            ->and($batch->created_at->timezoneName)->toBe('UTC')
            ->and($batch->filter['mode'])->toBe('filter')
            ->and($batch->filter['to'])->toBe('rejected')
            ->and($batch->filter['status'])->toBe('under_review');

        foreach ($submissions as $submission) {
            $change = SubmissionStatusChange::query()->where('submission_id', $submission->id)->sole();
            expect(Submission::query()->findOrFail($submission->id)->status)->toBe(SubmissionStatus::Rejected)
                ->and($change->decision_batch_id)->toBe($report->batchId)
                ->and($change->author_comment)->toBe('Gracias por participar');
        }
    });

    $audit = Activity::query()->where('event', 'submissions_decided_in_bulk')->sole();
    expect($audit->properties['total'])->toBe(3)
        ->and($audit->properties['filter']['status'])->toBe('under_review')
        ->and($audit->properties['decision_batch_id'])->toBe($report->batchId)
        // Sin datos personales: ni el comentario ni títulos.
        ->and(json_encode($audit->properties))->not->toContain('Gracias');
    // La decisión no envía correo: se avisa al publicar los resultados (DA-2).
    Queue::assertNotPushed(SendTransactionalMail::class);
});

it('CA-012-9: a bulk acceptance passes every proposal to the program in the same transaction', function (): void {
    [$organization, $event, $submissions, , $organizer] = bulkScenario(2, SubmissionStatus::Submitted);

    app(BulkDecideSubmissions::class)->handle($organizer, $event, ['to' => 'accepted', 'submission_ids' => bulkIds($submissions)]);

    foreach ($submissions as $submission) {
        expect(bulkStatus($organization, $submission))->toBe(SubmissionStatus::Accepted);
    }

    $activities = CurrentOrganization::run($organization, fn (): int => EventActivity::query()->whereIn('submission_id', bulkIds($submissions))->count());
    expect($activities)->toBe(2);
});

it('CA-012-9: a user without submissions.decide cannot decide in bulk', function (): void {
    [$organization, $event, $submissions, , , $reviewer] = bulkScenario(2);

    expect(fn () => app(BulkDecideSubmissions::class)->handle($reviewer, $event, ['to' => 'accepted', 'submission_ids' => bulkIds($submissions)]))
        ->toThrow(AuthorizationException::class);

    expect(bulkStatus($organization, $submissions[0]))->toBe(SubmissionStatus::UnderReview);
    expect(CurrentOrganization::run($organization, fn (): int => DecisionBatch::query()->count()))->toBe(0);
});

it('CA-012-9: an organizer of A cannot decide in bulk on the event of B', function (): void {
    [, $eventA, , , $organizerA] = bulkScenario(1);
    [$organizationB, $eventB, $submissionsB] = bulkScenario(2);

    expect(fn () => app(BulkDecideSubmissions::class)->handle($organizerA, $eventB, ['to' => 'accepted', 'submission_ids' => bulkIds($submissionsB)]))
        ->toThrow(AuthorizationException::class);
    // Los identificadores de B tampoco se resuelven desde el evento de A.
    expect(fn () => app(BulkDecideSubmissions::class)->handle($organizerA, $eventA, ['to' => 'accepted', 'submission_ids' => bulkIds($submissionsB)]))
        ->toThrow(ModelNotFoundException::class);

    expect(bulkStatus($organizationB, $submissionsB[0]))->toBe(SubmissionStatus::UnderReview);
});

it('CA-012-10: one ineligible proposal rejects the whole operation and says which one', function (): void {
    [$organization, $event, $submissions, , $organizer] = bulkScenario(3);
    // Una ya aceptada no puede pasar a rechazada directamente (DA-3).
    CurrentOrganization::run($organization, fn () => Submission::query()->whereKey($submissions[1]->id)->update(['status' => SubmissionStatus::Accepted]));

    try {
        app(BulkDecideSubmissions::class)->handle($organizer, $event, ['to' => 'rejected', 'submission_ids' => bulkIds($submissions)]);
        $this->fail('Debió rechazar la operación.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['submission_ids'][0])->toContain($submissions[1]->title)
            ->and($exception->errors()['submission_ids'][0])->not->toContain($submissions[0]->title);
    }

    expect(bulkStatus($organization, $submissions[0]))->toBe(SubmissionStatus::UnderReview)
        ->and(bulkStatus($organization, $submissions[2]))->toBe(SubmissionStatus::UnderReview)
        ->and(CurrentOrganization::run($organization, fn (): int => DecisionBatch::query()->count()))->toBe(0)
        ->and(CurrentOrganization::run($organization, fn (): int => SubmissionStatusChange::query()->count()))->toBe(0);
});

it('CA-012-10: a withdrawn proposal is not eligible either', function (): void {
    [$organization, $event, $submissions, , $organizer] = bulkScenario(2);
    CurrentOrganization::run($organization, fn () => Submission::query()->whereKey($submissions[0]->id)->update(['status' => SubmissionStatus::Withdrawn, 'withdrawn_at' => now()]));

    expect(fn () => app(BulkDecideSubmissions::class)->handle($organizer, $event, ['to' => 'waitlisted', 'submission_ids' => bulkIds($submissions)]))
        ->toThrow(ValidationException::class);
});

it('CA-012-11: a selection above the per-operation maximum is a validation error', function (): void {
    [$organization, $event, $submissions, , $organizer] = bulkScenario(1);
    $ids = array_map(static fn (): string => (string) Str::uuid(), range(1, BulkDecideRequest::MAX_SUBMISSIONS + 1));

    try {
        app(BulkDecideSubmissions::class)->handle($organizer, $event, ['to' => 'accepted', 'submission_ids' => $ids]);
        $this->fail('Debió rechazar el exceso.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('submission_ids');
    }

    expect(bulkStatus($organization, $submissions[0]))->toBe(SubmissionStatus::UnderReview);
});

it('CA-012-11: an empty selection, a duplicated id and an invalid decision are validation errors', function (array $data): void {
    [, $event, , , $organizer] = bulkScenario(1);

    expect(fn () => app(BulkDecideSubmissions::class)->handle($organizer, $event, $data))->toThrow(ValidationException::class);
})->with([
    'empty' => [['to' => 'accepted', 'submission_ids' => []]],
    'duplicated' => [['to' => 'accepted', 'submission_ids' => ['6c5f4d3a-8a1c-4e3a-9d4a-0a2b3c4d5e6f', '6c5f4d3a-8a1c-4e3a-9d4a-0a2b3c4d5e6f']]],
    'reverting is not a bulk decision' => [['to' => 'under_review', 'submission_ids' => ['6c5f4d3a-8a1c-4e3a-9d4a-0a2b3c4d5e6f']]],
]);

it('CA-012-11: an author comment over the maximum length is rejected', function (): void {
    [$organization, $event, $submissions, , $organizer] = bulkScenario(1);

    expect(fn () => app(BulkDecideSubmissions::class)->handle($organizer, $event, [
        'to' => 'accepted',
        'submission_ids' => bulkIds($submissions),
        'author_comment' => str_repeat('a', 1001),
    ]))->toThrow(ValidationException::class);

    expect(bulkStatus($organization, $submissions[0]))->toBe(SubmissionStatus::UnderReview);
});

it('CA-012-12: reverting a batch sends its proposals back to review and needs submissions.revert', function (): void {
    [$organization, $event, $submissions, $admin, $organizer] = bulkScenario(3);
    $report = app(BulkDecideSubmissions::class)->handle($organizer, $event, ['to' => 'waitlisted', 'submission_ids' => bulkIds($submissions)]);
    $batch = CurrentOrganization::run($organization, fn (): DecisionBatch => DecisionBatch::query()->findOrFail($report->batchId));

    // El organizador decide pero no revierte: la reversa es del administrador (DA-1).
    expect(fn () => app(RevertDecisionBatch::class)->handle($organizer, $batch))->toThrow(AuthorizationException::class);
    expect(bulkStatus($organization, $submissions[0]))->toBe(SubmissionStatus::Waitlisted);

    $reversal = app(RevertDecisionBatch::class)->handle($admin, $batch, 'Se decidio con el filtro equivocado');

    expect($reversal->reverted)->toBe(3)->and($reversal->skippedCount())->toBe(0);

    foreach ($submissions as $submission) {
        expect(bulkStatus($organization, $submission))->toBe(SubmissionStatus::UnderReview);
    }

    // El historial conserva la decisión y la reversa de cada propuesta.
    $changes = CurrentOrganization::run($organization, fn () => SubmissionStatusChange::query()->where('submission_id', $submissions[0]->id)->orderBy('created_at')->get());
    expect($changes)->toHaveCount(2)
        ->and($changes[1]->to_status)->toBe(SubmissionStatus::UnderReview)
        ->and($changes[1]->comment)->toBe('Se decidio con el filtro equivocado');
    $audit = Activity::query()->where('event', 'decision_batch_reverted')->sole();
    expect($audit->properties['reverted'])->toBe(3);
});

it('CA-012-12: reverting a batch of accepted proposals hides what passed to the program', function (): void {
    [$organization, $event, $submissions, $admin] = bulkScenario(2, SubmissionStatus::Submitted);
    $report = app(BulkDecideSubmissions::class)->handle($admin, $event, ['to' => 'accepted', 'submission_ids' => bulkIds($submissions)]);
    $batch = CurrentOrganization::run($organization, fn (): DecisionBatch => DecisionBatch::query()->findOrFail($report->batchId));

    $reversal = app(RevertDecisionBatch::class)->handle($admin, $batch);

    expect($reversal->reverted)->toBe(2)->and($reversal->programNotices)->not->toBeEmpty();
    $published = CurrentOrganization::run($organization, fn (): int => EventActivity::query()->whereIn('submission_id', bulkIds($submissions))->where('is_published', true)->count());
    expect($published)->toBe(0);
});

it('CA-012-12: a batch of another organization cannot be reverted', function (): void {
    [, , , $adminA] = bulkScenario(1);
    [$organizationB, $eventB, $submissionsB, $adminB] = bulkScenario(2);
    $report = app(BulkDecideSubmissions::class)->handle($adminB, $eventB, ['to' => 'rejected', 'submission_ids' => bulkIds($submissionsB)]);
    $batchB = CurrentOrganization::run($organizationB, fn (): DecisionBatch => DecisionBatch::query()->findOrFail($report->batchId));

    expect(fn () => app(RevertDecisionBatch::class)->handle($adminA, $batchB))->toThrow(AuthorizationException::class);
    expect(bulkStatus($organizationB, $submissionsB[0]))->toBe(SubmissionStatus::Rejected);
});

it('CA-012-13: a proposal that changed after the batch is left alone and listed as skipped', function (): void {
    [$organization, $event, $submissions, $admin] = bulkScenario(3);
    $report = app(BulkDecideSubmissions::class)->handle($admin, $event, ['to' => 'rejected', 'submission_ids' => bulkIds($submissions)]);
    $batch = CurrentOrganization::run($organization, fn (): DecisionBatch => DecisionBatch::query()->findOrFail($report->batchId));

    // Una se revierte a mano y se vuelve a decidir distinto.
    app(RevertSubmissionDecision::class)->handle($admin, bulkFresh($organization, $submissions[1]));
    app(AcceptSubmission::class)->handle($admin, bulkFresh($organization, $submissions[1]));

    $reversal = app(RevertDecisionBatch::class)->handle($admin, $batch);

    expect($reversal->reverted)->toBe(2)
        ->and($reversal->skippedCount())->toBe(1)
        ->and($reversal->skipped[0]['title'])->toBe($submissions[1]->title)
        ->and($reversal->skipped[0]['reason'])->toBe('status_changed')
        ->and(bulkStatus($organization, $submissions[1]))->toBe(SubmissionStatus::Accepted)
        ->and(bulkStatus($organization, $submissions[0]))->toBe(SubmissionStatus::UnderReview);
});

it('CA-012-13: reverting a batch twice changes nothing the second time and reports every proposal as skipped', function (): void {
    [$organization, $event, $submissions, $admin] = bulkScenario(2);
    $report = app(BulkDecideSubmissions::class)->handle($admin, $event, ['to' => 'rejected', 'submission_ids' => bulkIds($submissions)]);
    $batch = CurrentOrganization::run($organization, fn (): DecisionBatch => DecisionBatch::query()->findOrFail($report->batchId));

    app(RevertDecisionBatch::class)->handle($admin, $batch);
    $second = app(RevertDecisionBatch::class)->handle($admin, $batch);

    expect($second->reverted)->toBe(0)->and($second->skippedCount())->toBe(2);
});

it('CA-012-8: reverting a batch with published results asks for confirmation and sends no email', function (): void {
    Queue::fake();
    [$organization, $event, $submissions, $admin] = bulkScenario(2);
    $report = app(BulkDecideSubmissions::class)->handle($admin, $event, ['to' => 'rejected', 'submission_ids' => bulkIds($submissions)]);
    $batch = CurrentOrganization::run($organization, fn (): DecisionBatch => DecisionBatch::query()->findOrFail($report->batchId));
    CurrentOrganization::run($organization, fn () => Submission::query()->whereKey($submissions[0]->id)->update(['results_published_at' => now()]));

    expect(fn () => app(RevertDecisionBatch::class)->handle($admin, $batch))->toThrow(PublishedDecisionNeedsConfirmation::class);
    expect(bulkStatus($organization, $submissions[0]))->toBe(SubmissionStatus::Rejected)
        ->and(bulkStatus($organization, $submissions[1]))->toBe(SubmissionStatus::Rejected);

    $reversal = app(RevertDecisionBatch::class)->handle($admin, $batch, confirmedPublished: true);

    expect($reversal->reverted)->toBe(2);
    Queue::assertNotPushed(SendTransactionalMail::class);
});

it('CA-012-21: deciding in bulk once the call already published its results notifies at decision time, once', function (): void {
    Queue::fake();
    [$organization, $event, $submissions, , $organizer] = bulkScenario(2);
    CurrentOrganization::run($organization, fn () => CallForSubmissions::query()->whereKey($submissions[0]->call_for_submissions_id)->update(['results_published_at' => now()]));

    $report = app(BulkDecideSubmissions::class)->handle($organizer, $event, ['to' => 'accepted', 'submission_ids' => bulkIds($submissions)]);

    expect($report->publishedNow)->toBe(2);
    Queue::assertPushed(SendTransactionalMail::class, 2);
});

/** La propuesta tal como está en la base. */
function bulkFresh(Organization $organization, Submission $submission): Submission
{
    return CurrentOrganization::run($organization, fn (): Submission => Submission::query()->findOrFail($submission->id));
}
