<?php

declare(strict_types=1);

use App\Actions\Submissions\AcceptSubmission;
use App\Actions\Submissions\MarkSubmissionUnderReview;
use App\Actions\Submissions\RejectSubmission;
use App\Actions\Submissions\RevertSubmissionDecision;
use App\Actions\Submissions\WaitlistSubmission;
use App\Enums\OrganizationRole;
use App\Enums\SubmissionStatus;
use App\Exceptions\Domain\InvalidSubmissionTransition;
use App\Exceptions\Domain\PublishedDecisionNeedsConfirmation;
use App\Exceptions\Domain\SubmissionStatusConflict;
use App\Models\EventActivity;
use App\Models\Organization;
use App\Models\Speaker;
use App\Models\Submission;
use App\Models\SubmissionParticipant;
use App\Models\SubmissionStatusChange;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
});

/**
 * Organización con un administrador, un organizador y un evaluador, y una propuesta en el estado pedido.
 *
 * @return array{0: Organization, 1: Submission, 2: User, 3: User, 4: User}
 */
function decisionScenario(SubmissionStatus $status = SubmissionStatus::UnderReview): array
{
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $organizer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer);
    $reviewer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);
    $submission = CurrentOrganization::run($organization, fn (): Submission => $status === SubmissionStatus::Withdrawn
        ? Submission::factory()->withdrawn()->create()
        : Submission::factory()->create(['status' => $status]));

    return [$organization, $submission, $admin, $organizer, $reviewer];
}

/** Lo que hay en la base, sin pasar por la instancia en memoria. */
function storedSubmission(Organization $organization, Submission $submission): Submission
{
    return CurrentOrganization::run($organization, fn (): Submission => Submission::query()->findOrFail($submission->id));
}

/** @return list<SubmissionStatusChange> */
function storedChanges(Organization $organization, Submission $submission): array
{
    return CurrentOrganization::run($organization, fn (): array => SubmissionStatusChange::query()
        ->where('submission_id', $submission->id)->orderBy('created_at')->get()->all());
}

it('CA-012-1: an organizer accepts an under-review submission with history and audit', function (): void {
    [$organization, $submission, , $organizer] = decisionScenario();

    app(AcceptSubmission::class)->handle($organizer, $submission);

    $stored = storedSubmission($organization, $submission);
    $changes = storedChanges($organization, $submission);
    expect($stored->status)->toBe(SubmissionStatus::Accepted)
        ->and($changes)->toHaveCount(1)
        ->and($changes[0]->from_status)->toBe(SubmissionStatus::UnderReview)
        ->and($changes[0]->to_status)->toBe(SubmissionStatus::Accepted)
        ->and($changes[0]->actor_user_id)->toBe($organizer->id)
        ->and($changes[0]->event_id)->toBe($submission->event_id)
        ->and($changes[0]->created_at->timezoneName)->toBe('UTC');

    $audit = Activity::query()->where('event', 'submission_status_changed')->where('subject_id', $submission->id)->sole();
    expect($audit->causer_id)->toBe($organizer->id)
        ->and($audit->properties['from'])->toBe('under_review')
        ->and($audit->properties['to'])->toBe('accepted')
        ->and($audit->properties['organization_id'])->toBe($organization->id);
});

it('CA-012-1: the history records the UTC instant of the change', function (): void {
    [$organization, $submission, , $organizer] = decisionScenario();
    $this->travelTo(now()->setDate(2027, 1, 5)->setTime(15, 30));

    app(RejectSubmission::class)->handle($organizer, $submission);

    [$change] = storedChanges($organization, $submission);
    expect($change->created_at->utc()->format('Y-m-d H:i'))->toBe('2027-01-05 15:30');
});

it('CA-012-2: a submitted proposal is decided directly (accept, waitlist, reject) with history', function (string $action, SubmissionStatus $expected): void {
    [$organization, $submission, , $organizer] = decisionScenario(SubmissionStatus::Submitted);

    app($action)->handle($organizer, $submission);

    $changes = storedChanges($organization, $submission);
    expect(storedSubmission($organization, $submission)->status)->toBe($expected)
        ->and($changes)->toHaveCount(1)
        ->and($changes[0]->from_status)->toBe(SubmissionStatus::Submitted)
        ->and($changes[0]->to_status)->toBe($expected);
})->with([
    'accept' => [AcceptSubmission::class, SubmissionStatus::Accepted],
    'waitlist' => [WaitlistSubmission::class, SubmissionStatus::Waitlisted],
    'reject' => [RejectSubmission::class, SubmissionStatus::Rejected],
]);

it('CA-012-2: a waitlisted proposal is later accepted or rejected', function (string $action, SubmissionStatus $expected): void {
    [$organization, $submission, , $organizer] = decisionScenario(SubmissionStatus::Waitlisted);

    app($action)->handle($organizer, $submission);

    expect(storedSubmission($organization, $submission)->status)->toBe($expected);
})->with([
    'accept' => [AcceptSubmission::class, SubmissionStatus::Accepted],
    'reject' => [RejectSubmission::class, SubmissionStatus::Rejected],
]);

it('CA-012-3: an admin reverts accepted, waitlisted and rejected back to under review keeping the history', function (SubmissionStatus $from): void {
    [$organization, $submission, $admin] = decisionScenario($from);

    app(RevertSubmissionDecision::class)->handle($admin, $submission, 'Se decidio por error');

    $changes = storedChanges($organization, $submission);
    expect(storedSubmission($organization, $submission)->status)->toBe(SubmissionStatus::UnderReview)
        ->and($changes)->toHaveCount(1)
        ->and($changes[0]->from_status)->toBe($from)
        ->and($changes[0]->to_status)->toBe(SubmissionStatus::UnderReview)
        ->and($changes[0]->comment)->toBe('Se decidio por error');
})->with([SubmissionStatus::Accepted, SubmissionStatus::Waitlisted, SubmissionStatus::Rejected]);

it('CA-012-3: reverting does not delete earlier history', function (): void {
    [$organization, $submission, $admin, $organizer] = decisionScenario();

    app(RejectSubmission::class)->handle($organizer, $submission);
    app(RevertSubmissionDecision::class)->handle($admin, storedSubmission($organization, $submission));
    app(AcceptSubmission::class)->handle($organizer, storedSubmission($organization, $submission));

    $pairs = array_map(fn (SubmissionStatusChange $change): string => $change->from_status->value.'>'.$change->to_status->value, storedChanges($organization, $submission));
    expect($pairs)->toBe(['under_review>rejected', 'rejected>under_review', 'under_review>accepted']);
});

it('CA-012-3: reverting without submissions.revert is denied and nothing changes', function (OrganizationRole $role): void {
    $organization = Memberships::organization();
    $user = Memberships::attach(User::factory()->create(), $organization, $role);
    $submission = CurrentOrganization::run($organization, fn (): Submission => Submission::factory()->create(['status' => SubmissionStatus::Rejected]));

    expect(fn () => app(RevertSubmissionDecision::class)->handle($user, $submission))->toThrow(AuthorizationException::class);

    expect(storedSubmission($organization, $submission)->status)->toBe(SubmissionStatus::Rejected)
        ->and(storedChanges($organization, $submission))->toBe([]);
})->with([OrganizationRole::Organizer, OrganizationRole::Reviewer]);

it('CA-012-3: only org_admin holds submissions.revert and org_admin and organizer hold submissions.decide', function (): void {
    [$organization, $submission, $admin, $organizer, $reviewer] = decisionScenario();

    $can = fn (User $user, string $ability): bool => CurrentOrganization::run(
        $organization,
        fn (): bool => $user->unsetRelation('roles')->unsetRelation('permissions')->can($ability, $submission),
    );

    expect($can($admin, 'decide'))->toBeTrue()
        ->and($can($organizer, 'decide'))->toBeTrue()
        ->and($can($reviewer, 'decide'))->toBeFalse()
        ->and($can($admin, 'revert'))->toBeTrue()
        ->and($can($organizer, 'revert'))->toBeFalse()
        ->and($can($reviewer, 'revert'))->toBeFalse();
});

it('CA-012-1: a reviewer, a user without role and a stranger cannot decide', function (string $who): void {
    [$organization, $submission, , , $reviewer] = decisionScenario();
    $user = [
        'reviewer' => $reviewer,
        'no role' => Memberships::attach(User::factory()->create(), $organization),
        'stranger' => User::factory()->create(),
        'deactivated admin' => Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin, 'deactivated'),
    ][$who];

    foreach ([AcceptSubmission::class, RejectSubmission::class, WaitlistSubmission::class] as $action) {
        expect(fn () => app($action)->handle($user, $submission))->toThrow(AuthorizationException::class);
    }

    expect(storedSubmission($organization, $submission)->status)->toBe(SubmissionStatus::UnderReview)
        ->and(storedChanges($organization, $submission))->toBe([]);
})->with(['reviewer', 'no role', 'stranger', 'deactivated admin']);

it('CA-012-4: an invalid transition throws INVALID_SUBMISSION_TRANSITION and the state does not change', function (SubmissionStatus $from, string $action): void {
    [$organization, $submission, $admin] = decisionScenario($from);

    expect(fn () => app($action)->handle($admin, $submission))->toThrow(InvalidSubmissionTransition::class);

    expect(storedSubmission($organization, $submission)->status)->toBe($from)
        ->and(storedChanges($organization, $submission))->toBe([])
        ->and(Activity::query()->where('event', 'submission_status_changed')->count())->toBe(0);
})->with([
    'accepted to rejected' => [SubmissionStatus::Accepted, RejectSubmission::class],
    'accepted to waitlisted' => [SubmissionStatus::Accepted, WaitlistSubmission::class],
    'rejected to accepted' => [SubmissionStatus::Rejected, AcceptSubmission::class],
    'withdrawn to accepted' => [SubmissionStatus::Withdrawn, AcceptSubmission::class],
    'withdrawn to reverted' => [SubmissionStatus::Withdrawn, RevertSubmissionDecision::class],
    'under review reverted' => [SubmissionStatus::UnderReview, RevertSubmissionDecision::class],
    'submitted reverted' => [SubmissionStatus::Submitted, RevertSubmissionDecision::class],
]);

it('CA-012-5: an organizer of A cannot decide or revert a submission of B', function (): void {
    [, , , $organizerA] = decisionScenario();
    [$organizationB, $submissionB] = decisionScenario(SubmissionStatus::Accepted);

    expect(fn () => app(RejectSubmission::class)->handle($organizerA, $submissionB))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RevertSubmissionDecision::class)->handle($organizerA, $submissionB))->toThrow(AuthorizationException::class);

    expect(storedSubmission($organizationB, $submissionB)->status)->toBe(SubmissionStatus::Accepted);
});

it('CA-012-6: two organizers deciding at the same time apply a single transition and the other gets a conflict', function (): void {
    [$organization, $submission, , $first, $second] = decisionScenario();
    $second = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer);
    // Cada organizador cargó la pantalla con la propuesta «en revisión».
    $seenByFirst = storedSubmission($organization, $submission);
    $seenBySecond = storedSubmission($organization, $submission);

    app(AcceptSubmission::class)->handle($first, $seenByFirst);

    expect(fn () => app(RejectSubmission::class)->handle($second, $seenBySecond))->toThrow(SubmissionStatusConflict::class);

    $changes = storedChanges($organization, $submission);
    expect(storedSubmission($organization, $submission)->status)->toBe(SubmissionStatus::Accepted)
        ->and($changes)->toHaveCount(1)
        ->and($changes[0]->actor_user_id)->toBe($first->id);
});

it('CA-012-8: reverting a published decision requires explicit confirmation and sends no email', function (): void {
    Mail::fake();
    Notification::fake();
    Queue::fake();
    [$organization, $submission, $admin] = decisionScenario(SubmissionStatus::Accepted);
    CurrentOrganization::run($organization, fn () => Submission::query()->whereKey($submission->id)
        ->update(['results_published_at' => now()]));

    expect(fn () => app(RevertSubmissionDecision::class)->handle($admin, $submission))
        ->toThrow(PublishedDecisionNeedsConfirmation::class);
    expect(storedSubmission($organization, $submission)->status)->toBe(SubmissionStatus::Accepted);

    app(RevertSubmissionDecision::class)->handle($admin, $submission, confirmedPublished: true);

    $stored = storedSubmission($organization, $submission);
    expect($stored->status)->toBe(SubmissionStatus::UnderReview)
        ->and($stored->results_published_at)->toBeNull();
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Notification::assertNothingSent();
    Queue::assertNothingPushed();
});

it('CA-012-8: reverting an unpublished decision does not ask for confirmation', function (): void {
    [$organization, $submission, $admin] = decisionScenario(SubmissionStatus::Accepted);

    app(RevertSubmissionDecision::class)->handle($admin, $submission);

    expect(storedSubmission($organization, $submission)->status)->toBe(SubmissionStatus::UnderReview);
});

it('CA-012-1: accepting passes the submission to the program and reverting hides it (plan 021)', function (): void {
    [$organization, $submission, $admin] = decisionScenario(SubmissionStatus::Submitted);
    CurrentOrganization::run($organization, fn () => SubmissionParticipant::factory()->create([
        'submission_id' => $submission->id,
        'email' => 'ana@example.com',
    ]));

    app(AcceptSubmission::class)->handle($admin, $submission);

    $activity = CurrentOrganization::run($organization, fn (): EventActivity => EventActivity::query()->where('submission_id', $submission->id)->sole());
    expect($activity->is_published)->toBeTrue();

    $reversal = app(RevertSubmissionDecision::class)->handle($admin, storedSubmission($organization, $submission));

    $activity = CurrentOrganization::run($organization, fn (): EventActivity => EventActivity::query()->where('submission_id', $submission->id)->sole());
    expect($activity->is_published)->toBeFalse()
        ->and($reversal->activityHidden)->toBeTrue()
        ->and($reversal->speakersHidden)->toBe(1);
});

it('CA-012-1: a failure while promoting to the program rolls the acceptance back', function (): void {
    [$organization, $submission, $admin] = decisionScenario(SubmissionStatus::Submitted);
    CurrentOrganization::run($organization, fn () => SubmissionParticipant::factory()->create(['submission_id' => $submission->id]));
    // La aplicación de cada prueba es nueva: este oyente no sobrevive a la prueba.
    Speaker::creating(static function (): void {
        throw new RuntimeException('fallo simulado');
    });

    expect(fn () => app(AcceptSubmission::class)->handle($admin, $submission))->toThrow(RuntimeException::class);

    expect(storedSubmission($organization, $submission)->status)->toBe(SubmissionStatus::Submitted)
        ->and(storedChanges($organization, $submission))->toBe([]);
});

it('CA-012-27: the comment for the author is saved with the decision and the history', function (): void {
    [$organization, $submission, , $organizer] = decisionScenario();

    app(AcceptSubmission::class)->handle($organizer, $submission, 'Felicidades, nos gusto mucho.');

    $stored = storedSubmission($organization, $submission);
    [$change] = storedChanges($organization, $submission);
    expect($stored->author_comment)->toBe('Felicidades, nos gusto mucho.')
        ->and($change->author_comment)->toBe('Felicidades, nos gusto mucho.');
});

it('CA-012-27: without a comment the author comment stays empty, and reverting clears it', function (): void {
    [$organization, $submission, $admin, $organizer] = decisionScenario();

    app(WaitlistSubmission::class)->handle($organizer, $submission, '   ');
    expect(storedSubmission($organization, $submission)->author_comment)->toBeNull();

    app(RejectSubmission::class)->handle($organizer, storedSubmission($organization, $submission), 'Mas adelante.');
    expect(storedSubmission($organization, $submission)->author_comment)->toBe('Mas adelante.');

    app(RevertSubmissionDecision::class)->handle($admin, storedSubmission($organization, $submission));
    expect(storedSubmission($organization, $submission)->author_comment)->toBeNull();
});

it('CA-012-28: a comment with HTML or scripts is stored as inert plain text', function (): void {
    [$organization, $submission, , $organizer] = decisionScenario();

    app(AcceptSubmission::class)->handle($organizer, $submission, '<b>Hola</b> <script>alert(1)</script><a href="https://x.test">ver</a> [enlace](https://x.test)');

    $comment = (string) storedSubmission($organization, $submission)->author_comment;
    expect($comment)->not->toContain('<')
        ->and($comment)->not->toContain('script')
        ->and($comment)->not->toContain('alert')
        ->and($comment)->toStartWith('Hola');
});

it('CA-012-29: a comment longer than the maximum is rejected with a validation error and nothing changes', function (): void {
    [$organization, $submission, , $organizer] = decisionScenario();

    $call = fn () => app(AcceptSubmission::class)->handle($organizer, $submission, str_repeat('a', 1001));

    expect($call)->toThrow(ValidationException::class);
    expect(storedSubmission($organization, $submission)->status)->toBe(SubmissionStatus::UnderReview)
        ->and(storedChanges($organization, $submission))->toBe([]);

    app(AcceptSubmission::class)->handle($organizer, $submission, str_repeat('a', 1000));
    expect(strlen((string) storedSubmission($organization, $submission)->author_comment))->toBe(1000);
});

it('CA-012-1: the audit log does not carry the text of the comment', function (): void {
    [, $submission, , $organizer] = decisionScenario();

    app(AcceptSubmission::class)->handle($organizer, $submission, 'Texto privado para el autor');

    $audit = Activity::query()->where('event', 'submission_status_changed')->sole();
    expect(json_encode($audit->properties))->not->toContain('Texto privado')
        ->and($audit->properties['has_author_comment'])->toBeTrue();
});

// MarkSubmissionUnderReview: contrato que usa el plan 011 (AssignReviewers).

it('CA-012-1: MarkSubmissionUnderReview moves submitted to under_review with history', function (): void {
    [$organization, $submission, $admin] = decisionScenario(SubmissionStatus::Submitted);

    $changed = app(MarkSubmissionUnderReview::class)->handle($admin, $submission);

    $changes = storedChanges($organization, $submission);
    expect($changed)->toBeTrue()
        ->and(storedSubmission($organization, $submission)->status)->toBe(SubmissionStatus::UnderReview)
        ->and($changes)->toHaveCount(1)
        ->and($changes[0]->from_status)->toBe(SubmissionStatus::Submitted)
        ->and($changes[0]->to_status)->toBe(SubmissionStatus::UnderReview)
        ->and($changes[0]->actor_user_id)->toBe($admin->id);
});

it('CA-012-1: MarkSubmissionUnderReview is idempotent when already under review', function (): void {
    [$organization, $submission, $admin] = decisionScenario(SubmissionStatus::Submitted);

    app(MarkSubmissionUnderReview::class)->handle($admin, $submission);
    $again = app(MarkSubmissionUnderReview::class)->handle($admin, $submission);

    expect($again)->toBeFalse()
        ->and(storedSubmission($organization, $submission)->status)->toBe(SubmissionStatus::UnderReview)
        ->and(storedChanges($organization, $submission))->toHaveCount(1);
});

it('CA-012-1: MarkSubmissionUnderReview works without an actor (a job) and with the submission from another context', function (): void {
    [$organization, $submission] = decisionScenario(SubmissionStatus::Submitted);
    CurrentOrganization::forget();

    app(MarkSubmissionUnderReview::class)->handle(null, $submission);

    [$change] = storedChanges($organization, $submission);
    expect(storedSubmission($organization, $submission)->status)->toBe(SubmissionStatus::UnderReview)
        ->and($change->actor_user_id)->toBeNull();
});

it('CA-012-4: MarkSubmissionUnderReview refuses a decided or withdrawn submission', function (SubmissionStatus $status): void {
    [$organization, $submission, $admin] = decisionScenario($status);

    expect(fn () => app(MarkSubmissionUnderReview::class)->handle($admin, $submission))->toThrow(InvalidSubmissionTransition::class);

    expect(storedSubmission($organization, $submission)->status)->toBe($status)
        ->and(storedChanges($organization, $submission))->toBe([]);
})->with([SubmissionStatus::Accepted, SubmissionStatus::Rejected, SubmissionStatus::Withdrawn]);
