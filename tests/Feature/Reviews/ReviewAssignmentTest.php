<?php

declare(strict_types=1);

use App\Actions\Reviews\AssignReviewers;
use App\Actions\Reviews\RemoveReviewAssignment;
use App\Enums\OrganizationRole;
use App\Enums\ReviewAssignmentRemoval;
use App\Enums\ReviewAssignmentSkipReason;
use App\Enums\ReviewAssignmentSource;
use App\Enums\ReviewAssignmentStatus;
use App\Enums\SubmissionStatus;
use App\Jobs\SendTransactionalMail;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\EventAssignment;
use App\Models\Organization;
use App\Models\ReviewAssignment;
use App\Models\Rubric;
use App\Models\Submission;
use App\Models\SubmissionParticipant;
use App\Models\SubmissionStatusChange;
use App\Models\Track;
use App\Models\User;
use App\Support\Reviews\ReviewCoverage;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/form-helpers.php';

/*
 * Plan 011, F3a: asignar y quitar evaluadores. Cada prueba usa un solo rol por organización: la caché de
 * permisos de Spatie se comparte dentro del proceso.
 */

afterEach(function (): void {
    CurrentOrganization::forget();
});

/** Un evaluador: integrante con rol `reviewer` y, salvo que se pida lo contrario, asignación de evento de evaluador. */
function raReviewer(Organization $organization, Event $event, ?string $email = null, bool $assignedToEvent = true): User
{
    $user = Memberships::attach(
        User::factory()->create($email === null ? [] : ['email' => $email]),
        $organization,
        OrganizationRole::Reviewer,
    );

    if ($assignedToEvent) {
        EventAssignment::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id, 'user_id' => $user->id]);
    }

    return $user;
}

/**
 * Una propuesta del evento. Con `$base` reutiliza su convocatoria, tipo, formulario y aviso (el aviso es
 * único por evento y versión, y la factory crea uno nuevo de cada uno por propuesta).
 *
 * @param  array<string, mixed>  $overrides
 */
function raSubmission(Event $event, ?Submission $base = null, array $overrides = []): Submission
{
    $shared = $base === null ? [] : [
        'call_for_submissions_id' => $base->call_for_submissions_id,
        'submission_type_id' => $base->submission_type_id,
        'form_definition_id' => $base->form_definition_id,
        'track_id' => $base->track_id,
        'privacy_notice_version_id' => $base->privacy_notice_version_id,
    ];

    return Submission::factory()->create([
        'organization_id' => $event->organization_id,
        'event_id' => $event->id,
        ...$shared,
        ...$overrides,
    ]);
}

function raParticipant(Submission $submission, string $email, int $position = 1): SubmissionParticipant
{
    return SubmissionParticipant::factory()->create([
        'organization_id' => $submission->organization_id,
        'submission_id' => $submission->id,
        'email' => $email,
        'position' => $position,
        'is_primary' => $position === 1,
    ]);
}

/** @return list<SendTransactionalMail> */
function raQueuedAssignmentMails(): array
{
    return Queue::pushed(SendTransactionalMail::class, fn (SendTransactionalMail $job): bool => $job->notificationKey === 'reviewer.assignment')->values()->all();
}

function raAssignmentCount(): int
{
    return ReviewAssignment::query()->withoutGlobalScopes()->count();
}

it('CA-011-5: assigning a track gives every submission of the track the pending assignments', function (): void {
    [$organization, $event, , $admin] = formScenario();
    $track = Track::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id]);
    $first = raSubmission($event, null, ['track_id' => $track->id]);
    $second = raSubmission($event, $first);
    $otherTrack = raSubmission($event, $first, ['track_id' => Track::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id])->id]);
    Rubric::factory()->published()->create([
        'organization_id' => $organization->id, 'event_id' => $event->id, 'submission_type_id' => $first->submission_type_id, 'min_reviewers' => 2,
    ]);
    $reviewerA = raReviewer($organization, $event);
    $reviewerB = raReviewer($organization, $event);

    $report = app(AssignReviewers::class)->handle($admin, $event, [
        'scope' => 'track', 'track_id' => $track->id, 'reviewer_ids' => [$reviewerA->id, $reviewerB->id],
    ]);

    CurrentOrganization::run($organization, function () use ($first, $second, $otherTrack, $report): void {
        foreach ([$first, $second] as $submission) {
            $assignments = ReviewAssignment::query()->where('submission_id', $submission->id)->get();
            expect($assignments)->toHaveCount(2)
                ->and($assignments->pluck('status')->unique()->all())->toBe([ReviewAssignmentStatus::Pending]);
        }
        expect(ReviewAssignment::query()->where('submission_id', $otherTrack->id)->exists())->toBeFalse()
            ->and($report->assignedCount())->toBe(4);
    });
});

it('CA-011-6: a submission with fewer active assignments than the rubric minimum has incomplete coverage', function (): void {
    [$organization, $event, , $admin] = formScenario();
    $submission = raSubmission($event);
    Rubric::factory()->published()->create([
        'organization_id' => $organization->id, 'event_id' => $event->id, 'submission_type_id' => $submission->submission_type_id, 'min_reviewers' => 2,
    ]);
    $reviewerA = raReviewer($organization, $event);
    $reviewerB = raReviewer($organization, $event);

    app(AssignReviewers::class)->handle($admin, $event, ['scope' => 'submission', 'submission_ids' => [$submission->id], 'reviewer_ids' => [$reviewerA->id]]);

    CurrentOrganization::run($organization, function () use ($event, $submission, $reviewerB, $admin): void {
        $coverage = fn () => ReviewCoverage::for(Event::query()->findOrFail($event->id));
        expect($coverage()->minimumFor($submission->submission_type_id))->toBe(2)
            ->and($coverage()->isIncomplete($submission->submission_type_id, 1))->toBeTrue();

        app(AssignReviewers::class)->handle($admin, $event, ['scope' => 'submission', 'submission_ids' => [$submission->id], 'reviewer_ids' => [$reviewerB->id]]);
        $active = ReviewAssignment::query()->where('submission_id', $submission->id)->count();

        expect($coverage()->isIncomplete($submission->submission_type_id, $active))->toBeFalse();
    });
});

it('CA-011-6: without a published rubric the coverage minimum is the default of two', function (): void {
    [, $event] = formScenario();
    $submission = raSubmission($event);

    CurrentOrganization::run(Organization::query()->findOrFail($event->organization_id), function () use ($event, $submission): void {
        $coverage = ReviewCoverage::for(Event::query()->findOrFail($event->id));

        expect($coverage->minimumFor($submission->submission_type_id))->toBe(ReviewCoverage::DEFAULT_MIN_REVIEWERS)
            ->and($coverage->isIncomplete($submission->submission_type_id, 1))->toBeTrue()
            ->and($coverage->isIncomplete($submission->submission_type_id, 2))->toBeFalse();
    });
});

it('CA-011-7: a reviewer whose email matches the author or any participant is skipped, ignoring case', function (): void {
    [$organization, $event, , $admin] = formScenario();
    $submission = raSubmission($event);
    raParticipant($submission, 'autor@example.test', 1);
    raParticipant($submission, 'Coautor@Example.TEST', 2);
    $asAuthor = raReviewer($organization, $event, 'AUTOR@example.test');
    $asCoauthor = raReviewer($organization, $event, ' coautor@example.test ');
    $clean = raReviewer($organization, $event);

    $report = app(AssignReviewers::class)->handle($admin, $event, [
        'scope' => 'submission', 'submission_ids' => [$submission->id], 'reviewer_ids' => [$asAuthor->id, $asCoauthor->id, $clean->id],
    ]);

    $conflicts = $report->skippedFor(ReviewAssignmentSkipReason::ConflictOfInterest);
    expect(collect($conflicts)->pluck('reviewerId')->sort()->values()->all())->toBe(collect([$asAuthor->id, $asCoauthor->id])->sort()->values()->all())
        ->and($report->assigned)->toBe([['submission_id' => $submission->id, 'reviewer_id' => $clean->id]])
        ->and(raAssignmentCount())->toBe(1);
});

it('CA-011-41: every conflicting pair is listed with its reason and the others are still created', function (): void {
    [$organization, $event, , $admin] = formScenario();
    $conflicted = raSubmission($event);
    raParticipant($conflicted, 'autor@example.test');
    $clean = raSubmission($event, $conflicted);
    $reviewer = raReviewer($organization, $event, 'autor@example.test');

    $report = app(AssignReviewers::class)->handle($admin, $event, [
        'scope' => 'submission', 'submission_ids' => [$conflicted->id, $clean->id], 'reviewer_ids' => [$reviewer->id],
    ]);

    expect($report->skipped)->toHaveCount(1)
        ->and($report->skipped[0]->submissionId)->toBe($conflicted->id)
        ->and($report->skipped[0]->submissionTitle)->toBe($conflicted->title)
        ->and($report->skipped[0]->reviewerId)->toBe($reviewer->id)
        ->and($report->skipped[0]->reviewerName)->toBe($reviewer->name)
        ->and($report->skipped[0]->reason)->toBe(ReviewAssignmentSkipReason::ConflictOfInterest)
        ->and($report->skippedByReason())->toBe(['conflict_of_interest' => 1])
        ->and($report->assigned)->toBe([['submission_id' => $clean->id, 'reviewer_id' => $reviewer->id]]);
});

it('CA-011-8: a new assignment is audited and queues one email; if the email fails the assignment persists and the failure shows', function (): void {
    [$organization, $event, , $admin] = formScenario();
    $submission = raSubmission($event);
    $reviewer = raReviewer($organization, $event);
    Queue::fake();

    $report = app(AssignReviewers::class)->handle($admin, $event, ['scope' => 'submission', 'submission_ids' => [$submission->id], 'reviewer_ids' => [$reviewer->id]]);

    $log = Activity::query()->where('event', 'review_assignments_created')->sole();
    expect($log->causer_id)->toBe($admin->id)
        ->and($log->subject_id)->toBe($submission->id)
        ->and($log->properties['reviewer_ids'])->toBe([$reviewer->id])
        ->and($log->properties['organization_id'])->toBe($organization->id)
        ->and(raQueuedAssignmentMails())->toHaveCount(1)
        ->and($report->notificationFailures)->toBe([]);

    // Si encolar el correo falla, la asignación ya está confirmada y el fallo se devuelve.
    $second = raSubmission($event, $submission);
    $this->mock(Dispatcher::class, fn (MockInterface $mock) => $mock->shouldReceive('dispatch')->andThrow(new RuntimeException('cola caida')));

    $failed = app(AssignReviewers::class)->handle($admin, $event, ['scope' => 'submission', 'submission_ids' => [$second->id], 'reviewer_ids' => [$reviewer->id]]);

    expect($failed->notificationFailures)->toBe([$reviewer->id])
        ->and($failed->assignedCount())->toBe(1)
        ->and(raAssignmentCount())->toBe(2);
});

it('CA-011-9: the first assignment moves a submitted submission to under_review with history; under_review stays as is', function (): void {
    [$organization, $event, , $admin] = formScenario();
    $submitted = raSubmission($event);
    $underReview = raSubmission($event, $submitted, ['status' => SubmissionStatus::UnderReview]);
    $reviewerA = raReviewer($organization, $event);
    $reviewerB = raReviewer($organization, $event);

    app(AssignReviewers::class)->handle($admin, $event, [
        'scope' => 'submission', 'submission_ids' => [$submitted->id, $underReview->id], 'reviewer_ids' => [$reviewerA->id],
    ]);
    // La segunda asignación de la misma propuesta no vuelve a cambiar el estado ni a duplicar el historial.
    app(AssignReviewers::class)->handle($admin, $event, [
        'scope' => 'submission', 'submission_ids' => [$submitted->id], 'reviewer_ids' => [$reviewerB->id],
    ]);

    CurrentOrganization::run($organization, function () use ($submitted, $underReview): void {
        expect(Submission::query()->findOrFail($submitted->id)->status)->toBe(SubmissionStatus::UnderReview)
            ->and(Submission::query()->findOrFail($underReview->id)->status)->toBe(SubmissionStatus::UnderReview)
            ->and(SubmissionStatusChange::query()->where('submission_id', $submitted->id)->count())->toBe(1)
            ->and(SubmissionStatusChange::query()->where('submission_id', $underReview->id)->count())->toBe(0);
    });
});

it('CA-011-9: a submission whose every pair was skipped stays submitted', function (): void {
    [$organization, $event, , $admin] = formScenario();
    $submission = raSubmission($event);
    raParticipant($submission, 'autor@example.test');
    $reviewer = raReviewer($organization, $event, 'autor@example.test');

    app(AssignReviewers::class)->handle($admin, $event, ['scope' => 'submission', 'submission_ids' => [$submission->id], 'reviewer_ids' => [$reviewer->id]]);

    CurrentOrganization::run($organization, fn () => expect(Submission::query()->findOrFail($submission->id)->status)->toBe(SubmissionStatus::Submitted));
});

it('CA-011-10: a user without a reviewer assignment in the event is rejected and nothing is created', function (string $case): void {
    [$organization, $event, , $admin] = formScenario();
    $submission = raSubmission($event);
    $good = raReviewer($organization, $event);
    $bad = match ($case) {
        'no event assignment' => raReviewer($organization, $event, null, false),
        'coordinator only' => (function () use ($organization, $event): User {
            $user = raReviewer($organization, $event, null, false);
            EventAssignment::factory()->coordinator()->create(['organization_id' => $organization->id, 'event_id' => $event->id, 'user_id' => $user->id]);

            return $user;
        })(),
        'deactivated member' => (function () use ($organization, $event): User {
            $user = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);
            EventAssignment::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id, 'user_id' => $user->id]);
            $user->organizations()->updateExistingPivot($organization->id, ['status' => 'deactivated', 'deactivated_at' => now()]);

            return $user;
        })(),
        'assigned to another event' => (function () use ($organization): User {
            $otherEvent = Event::factory()->create(['organization_id' => $organization->id]);

            return raReviewer($organization, $otherEvent);
        })(),
        default => throw new InvalidArgumentException("Caso desconocido: {$case}"),
    };

    expect(validationErrorKeys(fn () => app(AssignReviewers::class)->handle($admin, $event, [
        'scope' => 'submission', 'submission_ids' => [$submission->id], 'reviewer_ids' => [$good->id, $bad->id],
    ])))->toBe(['reviewer_ids'])
        ->and(raAssignmentCount())->toBe(0);
})->with(['no event assignment', 'coordinator only', 'deactivated member', 'assigned to another event']);

it('CA-011-38: assigning a call gives every eligible submission of the call to each reviewer, with no stored rule', function (): void {
    [$organization, $event, , $admin] = formScenario();
    $call = CallForSubmissions::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id]);
    $first = raSubmission($event, null, ['call_for_submissions_id' => $call->id]);
    $second = raSubmission($event, $first, ['track_id' => Track::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id])->id]);
    $otherCall = CallForSubmissions::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id]);
    $outside = raSubmission($event, $first, ['call_for_submissions_id' => $otherCall->id]);
    $reviewerA = raReviewer($organization, $event);
    $reviewerB = raReviewer($organization, $event);

    $report = app(AssignReviewers::class)->handle($admin, $event, [
        'scope' => 'call', 'call_id' => $call->id, 'reviewer_ids' => [$reviewerA->id, $reviewerB->id],
    ]);

    CurrentOrganization::run($organization, function () use ($first, $second, $outside, $report): void {
        expect(ReviewAssignment::query()->whereIn('submission_id', [$first->id, $second->id])->count())->toBe(4)
            ->and(ReviewAssignment::query()->where('submission_id', $outside->id)->exists())->toBeFalse()
            ->and(ReviewAssignment::query()->pluck('source')->unique()->all())->toBe([ReviewAssignmentSource::Manual])
            ->and($report->skippedCount())->toBe(0);
    });
    expect(Schema::hasTable('review_assignment_scopes'))->toBeFalse();
});

it('CA-011-39: assigning selected submissions touches only those, as manual', function (): void {
    [$organization, $event, , $admin] = formScenario();
    $chosen = raSubmission($event);
    $left = raSubmission($event, $chosen);
    $reviewer = raReviewer($organization, $event);

    app(AssignReviewers::class)->handle($admin, $event, ['scope' => 'submission', 'submission_ids' => [$chosen->id], 'reviewer_ids' => [$reviewer->id]]);

    CurrentOrganization::run($organization, function () use ($chosen, $left, $admin): void {
        $assignment = ReviewAssignment::query()->sole();
        expect($assignment->submission_id)->toBe($chosen->id)
            ->and($assignment->source)->toBe(ReviewAssignmentSource::Manual)
            ->and($assignment->assigned_by)->toBe($admin->id)
            ->and($assignment->status)->toBe(ReviewAssignmentStatus::Pending)
            ->and(ReviewAssignment::query()->where('submission_id', $left->id)->exists())->toBeFalse();
    });
});

it('CA-011-40: withdrawn, invited and already decided submissions are skipped and the report says how many and why', function (): void {
    [$organization, $event, , $admin] = formScenario();
    $eligible = raSubmission($event);
    $withdrawn = raSubmission($event, $eligible, ['status' => SubmissionStatus::Withdrawn, 'withdrawn_at' => now()]);
    $accepted = raSubmission($event, $eligible, ['status' => SubmissionStatus::Accepted]);
    $rejected = raSubmission($event, $eligible, ['status' => SubmissionStatus::Rejected]);
    $invited = Submission::factory()->invited()->create([
        'organization_id' => $organization->id, 'event_id' => $event->id, 'track_id' => $eligible->track_id,
    ]);
    $reviewer = raReviewer($organization, $event);

    $report = app(AssignReviewers::class)->handle($admin, $event, [
        'scope' => 'track', 'track_id' => $eligible->track_id, 'reviewer_ids' => [$reviewer->id],
    ]);

    expect($report->assigned)->toBe([['submission_id' => $eligible->id, 'reviewer_id' => $reviewer->id]])
        ->and($report->skippedByReason())->toBe(['ineligible_status' => 3, 'not_from_call' => 1])
        ->and(collect($report->skippedFor(ReviewAssignmentSkipReason::IneligibleStatus))->pluck('submissionId')->sort()->values()->all())
        ->toBe(collect([$withdrawn->id, $accepted->id, $rejected->id])->sort()->values()->all())
        ->and($report->skippedFor(ReviewAssignmentSkipReason::NotFromCall)[0]->submissionId)->toBe($invited->id);
});

it('CA-011-44: one email per reviewer and operation with the count and the dashboard link', function (): void {
    [$organization, $event, , $admin] = formScenario();
    $base = raSubmission($event);
    $ids = [$base->id];
    for ($i = 1; $i < 12; $i++) {
        $ids[] = raSubmission($event, $base)->id;
    }
    $reviewerA = raReviewer($organization, $event);
    $reviewerB = raReviewer($organization, $event);
    Queue::fake();

    app(AssignReviewers::class)->handle($admin, $event, ['scope' => 'submission', 'submission_ids' => $ids, 'reviewer_ids' => [$reviewerA->id]]);

    $mails = raQueuedAssignmentMails();
    expect($mails)->toHaveCount(1)
        ->and($mails[0]->recipient->email)->toBe($reviewerA->email)
        ->and($mails[0]->variables['assigned_count'])->toBe('12')
        ->and($mails[0]->variables['dashboard_url'])->toContain($organization->slug)
        ->and($mails[0]->variables['reviewer_name'])->toBe($reviewerA->name);

    // Repetir la operación no asigna nada nuevo, así que no hay otro correo; una segunda evaluadora recibe el suyo.
    app(AssignReviewers::class)->handle($admin, $event, ['scope' => 'submission', 'submission_ids' => $ids, 'reviewer_ids' => [$reviewerA->id]]);
    expect(raQueuedAssignmentMails())->toHaveCount(1);

    app(AssignReviewers::class)->handle($admin, $event, ['scope' => 'submission', 'submission_ids' => $ids, 'reviewer_ids' => [$reviewerA->id, $reviewerB->id]]);
    expect(raQueuedAssignmentMails())->toHaveCount(2);
});

it('is idempotent: repeating an operation creates nothing and reports the pairs as already assigned', function (): void {
    [$organization, $event, , $admin] = formScenario();
    $submission = raSubmission($event);
    $reviewer = raReviewer($organization, $event);
    $data = ['scope' => 'submission', 'submission_ids' => [$submission->id], 'reviewer_ids' => [$reviewer->id]];

    app(AssignReviewers::class)->handle($admin, $event, $data);
    $again = app(AssignReviewers::class)->handle($admin, $event, $data);

    expect($again->assignedCount())->toBe(0)
        ->and($again->skippedByReason())->toBe(['already_assigned' => 1])
        ->and(raAssignmentCount())->toBe(1);
});

it('CA-011-45: removing a pending assignment deletes it and audits it', function (): void {
    [$organization, $event, , $admin] = formScenario();
    $assignment = ReviewAssignment::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id]);

    $outcome = app(RemoveReviewAssignment::class)->handle($admin, $event, ['assignment_id' => $assignment->id]);

    $log = Activity::query()->where('event', 'review_assignment_removed')->sole();
    expect($outcome)->toBe(ReviewAssignmentRemoval::Deleted)
        ->and(raAssignmentCount())->toBe(0)
        ->and($log->causer_id)->toBe($admin->id)
        ->and($log->properties['old']['status'])->toBe('pending')
        ->and($log->properties['new']['outcome'])->toBe('deleted');
});

it('CA-011-45: an in-progress or submitted assignment is cancelled, kept, audited and stops counting for coverage', function (ReviewAssignmentStatus $status): void {
    [$organization, $event, , $admin] = formScenario();
    $assignment = ReviewAssignment::factory()->status($status)->create(['organization_id' => $organization->id, 'event_id' => $event->id]);

    $outcome = app(RemoveReviewAssignment::class)->handle($admin, $event, ['assignment_id' => $assignment->id]);

    CurrentOrganization::run($organization, function () use ($assignment, $outcome, $status): void {
        $kept = ReviewAssignment::query()->findOrFail($assignment->id);
        $active = Submission::query()->withCount(['reviewAssignments as active' => fn ($query) => $query->whereIn('status', ReviewAssignmentStatus::coverageValues())])
            ->findOrFail($assignment->submission_id);

        expect($outcome)->toBe(ReviewAssignmentRemoval::Cancelled)
            ->and($kept->status)->toBe(ReviewAssignmentStatus::Cancelled)
            ->and($active->getAttribute('active'))->toBe(0)
            ->and(Activity::query()->where('event', 'review_assignment_removed')->sole()->properties['old']['status'])->toBe($status->value);
    });
})->with([ReviewAssignmentStatus::InProgress, ReviewAssignmentStatus::Submitted]);

it('CA-011-45: removing is idempotent and a cancelled pair is not reassigned silently', function (): void {
    [$organization, $event, , $admin] = formScenario();
    $assignment = ReviewAssignment::factory()->status(ReviewAssignmentStatus::Submitted)->create(['organization_id' => $organization->id, 'event_id' => $event->id]);
    app(RemoveReviewAssignment::class)->handle($admin, $event, ['assignment_id' => $assignment->id]);
    EventAssignment::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id, 'user_id' => $assignment->reviewer_id]);

    $again = app(RemoveReviewAssignment::class)->handle($admin, $event, ['assignment_id' => $assignment->id]);
    $report = app(AssignReviewers::class)->handle($admin, $event, [
        'scope' => 'submission', 'submission_ids' => [$assignment->submission_id], 'reviewer_ids' => [$assignment->reviewer_id],
    ]);

    expect($again)->toBe(ReviewAssignmentRemoval::Cancelled)
        ->and(Activity::query()->where('event', 'review_assignment_removed')->count())->toBe(1)
        ->and($report->skippedByReason())->toBe(['previously_cancelled' => 1])
        ->and(raAssignmentCount())->toBe(1);
});

it('CA-011-45: a declared conflict is kept as cancelled instead of being erased', function (): void {
    [$organization, $event, , $admin] = formScenario();
    $assignment = ReviewAssignment::factory()->status(ReviewAssignmentStatus::DeclinedConflict)->create(['organization_id' => $organization->id, 'event_id' => $event->id]);

    $outcome = app(RemoveReviewAssignment::class)->handle($admin, $event, ['assignment_id' => $assignment->id]);

    expect($outcome)->toBe(ReviewAssignmentRemoval::Cancelled)
        ->and(raAssignmentCount())->toBe(1);
});

it('CA-011-46: only roles with review_assignments.manage assign or remove, whatever the scope', function (OrganizationRole $role, bool $allowed): void {
    $organization = Memberships::organization();
    $user = Memberships::attach(User::factory()->create(), $organization, $role);
    $event = Event::factory()->create(['organization_id' => $organization->id]);
    $submission = raSubmission($event);
    $reviewer = raReviewer($organization, $event);
    $assignment = ReviewAssignment::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id, 'submission_id' => raSubmission($event, $submission)->id]);
    $inputs = [
        ['scope' => 'submission', 'submission_ids' => [$submission->id]],
        ['scope' => 'track', 'track_id' => $submission->track_id],
        ['scope' => 'call', 'call_id' => $submission->call_for_submissions_id],
    ];

    foreach ($inputs as $input) {
        $attempt = fn () => app(AssignReviewers::class)->handle($user, $event, [...$input, 'reviewer_ids' => [$reviewer->id]]);
        $allowed ? $attempt() : expect($attempt)->toThrow(AuthorizationException::class);
    }

    $remove = fn () => app(RemoveReviewAssignment::class)->handle($user, $event, ['assignment_id' => $assignment->id]);
    $allowed ? $remove() : expect($remove)->toThrow(AuthorizationException::class);

    // Sin permiso no cambia nada: la asignación previa sigue pendiente y no se creó ninguna.
    if (! $allowed) {
        expect(raAssignmentCount())->toBe(1)
            ->and(ReviewAssignment::query()->withoutGlobalScopes()->sole()->status)->toBe(ReviewAssignmentStatus::Pending);

        return;
    }

    // Con permiso sí cambió algo: se creó la asignación del evaluador y se quitó la pendiente.
    expect(ReviewAssignment::query()->withoutGlobalScopes()->whereKey($assignment->id)->exists())->toBeFalse()
        ->and(ReviewAssignment::query()->withoutGlobalScopes()->where('reviewer_id', $reviewer->id)->exists())->toBeTrue();
})->with([
    'org admin' => [OrganizationRole::OrgAdmin, true],
    'organizer' => [OrganizationRole::Organizer, true],
    'reviewer' => [OrganizationRole::Reviewer, false],
]);

it('CA-011-46: a user of another organization or without membership is denied', function (): void {
    [$organization, $event] = formScenario();
    $submission = raSubmission($event);
    $reviewer = raReviewer($organization, $event);
    $foreignAdmin = Memberships::attach(User::factory()->create(), Memberships::organization(), OrganizationRole::OrgAdmin);
    $stranger = User::factory()->create();

    foreach ([$foreignAdmin, $stranger] as $user) {
        expect(fn () => app(AssignReviewers::class)->handle($user, $event, [
            'scope' => 'submission', 'submission_ids' => [$submission->id], 'reviewer_ids' => [$reviewer->id],
        ]))->toThrow(AuthorizationException::class);
    }

    expect(raAssignmentCount())->toBe(0);
});

it('CA-011-46: the policy grants the permission and no other ability by default', function (): void {
    $organization = Memberships::organization();
    $organizer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer);
    $event = Event::factory()->create(['organization_id' => $organization->id]);
    $assignment = ReviewAssignment::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id]);

    CurrentOrganization::run($organization, function () use ($organizer, $event, $assignment): void {
        $gate = Gate::forUser($organizer);

        expect($gate->allows('viewAny', ReviewAssignment::class))->toBeTrue()
            ->and($gate->allows('view', $assignment))->toBeTrue()
            ->and($gate->allows('create', [ReviewAssignment::class, $event]))->toBeTrue()
            ->and($gate->allows('delete', $assignment))->toBeTrue()
            ->and($gate->allows('update', $assignment))->toBeFalse();
    });
});

it('CA-011-47: a track, call, submission or assignment of another event or organization answers 404 and creates nothing', function (): void {
    [$organization, $event, , $admin] = formScenario();
    $reviewer = raReviewer($organization, $event);
    $otherEvent = Event::factory()->create(['organization_id' => $organization->id]);
    [$foreignOrganization, $foreignEvent] = formScenario();

    $mine = raSubmission($event);
    $ofOtherEvent = raSubmission($otherEvent);
    $ofOtherOrganization = raSubmission($foreignEvent);
    $foreignAssignment = ReviewAssignment::factory()->create(['organization_id' => $foreignOrganization->id, 'event_id' => $foreignEvent->id, 'submission_id' => raSubmission($foreignEvent, $ofOtherOrganization)->id]);
    $otherEventAssignment = ReviewAssignment::factory()->create(['organization_id' => $organization->id, 'event_id' => $otherEvent->id, 'submission_id' => raSubmission($otherEvent, $ofOtherEvent)->id]);
    $before = raAssignmentCount();

    $attempts = [
        'track of another event' => ['scope' => 'track', 'track_id' => $ofOtherEvent->track_id],
        'track of another organization' => ['scope' => 'track', 'track_id' => $ofOtherOrganization->track_id],
        'call of another event' => ['scope' => 'call', 'call_id' => $ofOtherEvent->call_for_submissions_id],
        'call of another organization' => ['scope' => 'call', 'call_id' => $ofOtherOrganization->call_for_submissions_id],
        'submission of another event' => ['scope' => 'submission', 'submission_ids' => [$mine->id, $ofOtherEvent->id]],
        'submission of another organization' => ['scope' => 'submission', 'submission_ids' => [$mine->id, $ofOtherOrganization->id]],
        'nonexistent track' => ['scope' => 'track', 'track_id' => (string) Str::uuid()],
    ];

    foreach ($attempts as $label => $input) {
        expect(fn () => app(AssignReviewers::class)->handle($admin, $event, [...$input, 'reviewer_ids' => [$reviewer->id]]))
            ->toThrow(ModelNotFoundException::class, '', $label);
    }

    foreach ([$foreignAssignment, $otherEventAssignment] as $assignment) {
        expect(fn () => app(RemoveReviewAssignment::class)->handle($admin, $event, ['assignment_id' => $assignment->id]))
            ->toThrow(ModelNotFoundException::class);
    }

    expect(raAssignmentCount())->toBe($before);
});

it('rejects malformed input: unknown scope, no reviewers, a missing scope identifier and too many reviewers', function (): void {
    [$organization, $event, , $admin] = formScenario();
    $reviewer = raReviewer($organization, $event);

    $keys = fn (array $data): array => validationErrorKeys(fn () => app(AssignReviewers::class)->handle($admin, $event, $data));

    expect($keys(['scope' => 'everything', 'reviewer_ids' => [$reviewer->id]]))->toBe(['scope'])
        ->and($keys(['scope' => 'track', 'reviewer_ids' => []]))->toContain('reviewer_ids', 'track_id')
        ->and($keys(['scope' => 'call', 'reviewer_ids' => [$reviewer->id]]))->toBe(['call_id'])
        ->and($keys(['scope' => 'submission', 'reviewer_ids' => [$reviewer->id]]))->toBe(['submission_ids'])
        ->and($keys(['scope' => 'track', 'track_id' => (string) Str::uuid(), 'reviewer_ids' => array_fill(0, 51, (string) Str::uuid())]))->toContain('reviewer_ids', 'reviewer_ids.1');
});

it('is atomic: when the second submission fails nothing stays assigned and no submission changes state', function (): void {
    [$organization, $event, , $admin] = formScenario();
    $first = raSubmission($event);
    $second = raSubmission($event, $first);
    $reviewer = raReviewer($organization, $event);
    $ids = collect([$first->id, $second->id])->sort()->values()->all();

    // El historial de la segunda propuesta (en orden de id) falla: el primer paso ya estaba hecho.
    $failing = end($ids);
    DB::unprepared("CREATE FUNCTION ra_fail_history() RETURNS trigger AS \$\$ BEGIN IF NEW.submission_id = '{$failing}' THEN RAISE EXCEPTION 'fallo simulado'; END IF; RETURN NEW; END \$\$ LANGUAGE plpgsql");
    DB::unprepared('CREATE TRIGGER ra_fail_history_trigger BEFORE INSERT ON submission_status_changes FOR EACH ROW EXECUTE FUNCTION ra_fail_history()');

    expect(fn () => app(AssignReviewers::class)->handle($admin, $event, ['scope' => 'submission', 'submission_ids' => $ids, 'reviewer_ids' => [$reviewer->id]]))
        ->toThrow(QueryException::class);

    expect(raAssignmentCount())->toBe(0)
        ->and(SubmissionStatusChange::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(Submission::query()->withoutGlobalScopes()->where('status', SubmissionStatus::UnderReview)->count())->toBe(0);
});
