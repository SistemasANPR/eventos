<?php

declare(strict_types=1);

use App\Actions\Reviews\AssignReviewers;
use App\Actions\Reviews\RemoveScopeRule;
use App\Actions\Reviews\SyncScopedAssignments;
use App\Enums\OrganizationRole;
use App\Enums\ReviewAssignmentScope;
use App\Enums\ReviewAssignmentSkipReason;
use App\Enums\ReviewAssignmentSource;
use App\Enums\SubmissionStatus;
use App\Jobs\SendTransactionalMail;
use App\Jobs\SyncScopedAssignmentsJob;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\EventAssignment;
use App\Models\Organization;
use App\Models\ReviewAssignment;
use App\Models\ReviewScopeRule;
use App\Models\Submission;
use App\Models\SubmissionParticipant;
use App\Models\Track;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/form-helpers.php';

/*
 * Plan 011, F3b: la regla permanente de alcance (`review_assignment_scopes`), su Action idempotente
 * `SyncScopedAssignments` y su job. Cada prueba usa un solo rol por organización: la caché de permisos de
 * Spatie se comparte dentro del proceso.
 */

afterEach(function (): void {
    CurrentOrganization::forget();
});

/** Un evaluador del evento: integrante con rol `reviewer` y asignación de evento de evaluador. */
function rsrReviewer(Organization $organization, Event $event, ?string $email = null): User
{
    $user = Memberships::attach(
        User::factory()->create($email === null ? [] : ['email' => $email]),
        $organization,
        OrganizationRole::Reviewer,
    );
    EventAssignment::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id, 'user_id' => $user->id]);

    return $user;
}

/** @return array{0: Track, 1: CallForSubmissions} un eje y una convocatoria del evento */
function rsrScope(Organization $organization, Event $event): array
{
    return [
        Track::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id]),
        CallForSubmissions::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id]),
    ];
}

/**
 * Una propuesta del evento en un eje y una convocatoria dados; reutiliza tipo, formulario y aviso de `$base`
 * (el aviso es único por evento y versión).
 *
 * @param  array<string, mixed>  $overrides
 */
function rsrSubmission(Event $event, Track $track, CallForSubmissions $call, ?Submission $base = null, array $overrides = []): Submission
{
    $shared = $base === null ? [] : [
        'submission_type_id' => $base->submission_type_id,
        'form_definition_id' => $base->form_definition_id,
        'privacy_notice_version_id' => $base->privacy_notice_version_id,
    ];

    return Submission::factory()->create([
        'organization_id' => $event->organization_id,
        'event_id' => $event->id,
        'track_id' => $track->id,
        'call_for_submissions_id' => $call->id,
        ...$shared,
        ...$overrides,
    ]);
}

/** Guarda la regla a mano, sin pasar por `AssignReviewers`. */
function rsrRule(Organization $organization, Event $event, User $reviewer, Track|CallForSubmissions $scope): ReviewScopeRule
{
    return ReviewScopeRule::factory()
        ->state($scope instanceof Track
            ? ['track_id' => $scope->id, 'call_id' => null]
            : ['scope_type' => ReviewAssignmentScope::Call, 'call_id' => $scope->id, 'track_id' => null])
        ->create(['organization_id' => $organization->id, 'event_id' => $event->id, 'reviewer_id' => $reviewer->id]);
}

function rsrAssignmentCount(): int
{
    return ReviewAssignment::query()->withoutGlobalScopes()->count();
}

function rsrRuleCount(): int
{
    return ReviewScopeRule::query()->withoutGlobalScopes()->count();
}

it('CA-011-38: assigning a track or a call with save_rule stores one rule per reviewer and scope, and repeating does not duplicate it', function (string $scope): void {
    [$organization, $event, , $admin] = formScenario();
    [$track, $call] = rsrScope($organization, $event);
    $first = rsrSubmission($event, $track, $call);
    $reviewerA = rsrReviewer($organization, $event);
    $reviewerB = rsrReviewer($organization, $event);
    $data = [
        'scope' => $scope, 'reviewer_ids' => [$reviewerA->id, $reviewerB->id], 'save_rule' => true,
        ...($scope === 'track' ? ['track_id' => $track->id] : ['call_id' => $call->id]),
    ];
    Queue::fake();

    $report = app(AssignReviewers::class)->handle($admin, $event, $data);
    $again = app(AssignReviewers::class)->handle($admin, $event, $data);

    CurrentOrganization::run($organization, function () use ($scope, $first, $track, $call, $reviewerA, $reviewerB, $report, $again, $admin): void {
        $rules = ReviewScopeRule::query()->get();
        expect($rules)->toHaveCount(2)
            ->and($rules->pluck('reviewer_id')->sort()->values()->all())->toBe(collect([$reviewerA->id, $reviewerB->id])->sort()->values()->all())
            ->and($rules->pluck('scope_type.value')->unique()->all())->toBe([$scope])
            ->and($rules->pluck($scope === 'track' ? 'track_id' : 'call_id')->unique()->all())->toBe([$scope === 'track' ? $track->id : $call->id])
            ->and($rules->pluck('created_by')->unique()->all())->toBe([$admin->id])
            // La expansión de este momento sigue siendo manual (CA-011-38): lo de la regla llega después.
            ->and(ReviewAssignment::query()->where('submission_id', $first->id)->pluck('source')->unique()->all())->toBe([ReviewAssignmentSource::Manual])
            ->and($report->rulesCreated)->toBe(2)
            ->and($again->rulesCreated)->toBe(0)
            ->and(Activity::query()->where('event', 'review_scope_rule_created')->count())->toBe(2);
    });
})->with(['track', 'call']);

it('CA-011-39: assigning chosen submissions never stores a rule, and neither does a scope assignment without save_rule', function (): void {
    [$organization, $event, , $admin] = formScenario();
    [$track, $call] = rsrScope($organization, $event);
    $submission = rsrSubmission($event, $track, $call);
    $reviewer = rsrReviewer($organization, $event);
    Queue::fake();

    app(AssignReviewers::class)->handle($admin, $event, ['scope' => 'submission', 'submission_ids' => [$submission->id], 'reviewer_ids' => [$reviewer->id], 'save_rule' => true]);
    app(AssignReviewers::class)->handle($admin, $event, ['scope' => 'track', 'track_id' => $track->id, 'reviewer_ids' => [$reviewer->id]]);

    expect(rsrRuleCount())->toBe(0);
});

it('CA-011-42: a new proposal inside the scope is assigned by the rule, once, without mail, and moves to under_review', function (string $scope): void {
    [$organization, $event] = formScenario();
    [$track, $call] = rsrScope($organization, $event);
    $reviewer = rsrReviewer($organization, $event);
    rsrRule($organization, $event, $reviewer, $scope === 'track' ? $track : $call);
    $arrived = rsrSubmission($event, $track, $call);
    Queue::fake();

    (new SyncScopedAssignmentsJob($organization->id, $arrived->id))->handle(app(SyncScopedAssignments::class));
    (new SyncScopedAssignmentsJob($organization->id, $arrived->id))->handle(app(SyncScopedAssignments::class));

    CurrentOrganization::run($organization, function () use ($arrived, $reviewer): void {
        $assignment = ReviewAssignment::query()->sole();
        expect($assignment->submission_id)->toBe($arrived->id)
            ->and($assignment->reviewer_id)->toBe($reviewer->id)
            ->and($assignment->source)->toBe(ReviewAssignmentSource::Scope)
            ->and($assignment->assigned_by)->toBeNull()
            ->and(Submission::query()->findOrFail($arrived->id)->status)->toBe(SubmissionStatus::UnderReview);
    });
    Queue::assertNotPushed(SendTransactionalMail::class);
})->with(['track', 'call']);

it('CA-011-42: a proposal outside the scope, an invited one or one with a conflicting email is not assigned', function (): void {
    [$organization, $event] = formScenario();
    [$track, $call] = rsrScope($organization, $event);
    [$otherTrack, $otherCall] = rsrScope($organization, $event);
    $reviewer = rsrReviewer($organization, $event, 'evaluador@example.test');
    rsrRule($organization, $event, $reviewer, $track);
    $outside = rsrSubmission($event, $otherTrack, $otherCall);
    $invited = Submission::factory()->invited()->create([
        'organization_id' => $organization->id, 'event_id' => $event->id, 'track_id' => $track->id, 'submission_type_id' => $outside->submission_type_id,
    ]);
    $conflict = rsrSubmission($event, $track, $call, $outside);
    SubmissionParticipant::factory()->create(['organization_id' => $organization->id, 'submission_id' => $conflict->id, 'email' => 'Evaluador@Example.test']);
    Queue::fake();

    foreach ([$outside, $invited, $conflict] as $submission) {
        (new SyncScopedAssignmentsJob($organization->id, $submission->id))->handle(app(SyncScopedAssignments::class));
    }

    expect(rsrAssignmentCount())->toBe(0);
});

it('CA-011-42: running the sync twice in a row adds nothing the second time and lists no pair as skipped', function (): void {
    [$organization, $event, , $admin] = formScenario();
    [$track, $call] = rsrScope($organization, $event);
    $reviewer = rsrReviewer($organization, $event);
    rsrRule($organization, $event, $reviewer, $track);
    $first = rsrSubmission($event, $track, $call);
    rsrSubmission($event, $track, $call, $first);
    Queue::fake();

    $one = app(SyncScopedAssignments::class)->handle($admin, $event);
    $two = app(SyncScopedAssignments::class)->handle($admin, $event);

    expect($one->assignedCount())->toBe(2)
        ->and($two->assignedCount())->toBe(0)
        ->and($two->skipped)->toBe([])
        ->and(rsrAssignmentCount())->toBe(2);
});

it('CA-011-42: the manual sync catches up the proposals that arrived while the rule did not exist yet', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    [$track, $call] = rsrScope($organization, $event);
    $reviewer = rsrReviewer($organization, $event);
    $early = rsrSubmission($event, $track, $call);
    rsrRule($organization, $event, $reviewer, $track);
    $late = rsrSubmission($event, $track, $call, $early);
    Queue::fake();

    $report = app(SyncScopedAssignments::class)->handle($organizer, $event);

    expect($report->assignedCount())->toBe(2)
        ->and(collect($report->assigned)->pluck('submission_id')->sort()->values()->all())->toBe(collect([$early->id, $late->id])->sort()->values()->all());
    Queue::assertNotPushed(SendTransactionalMail::class);
});

it('CA-011-42: a rule whose reviewer is no longer a reviewer of the event is skipped with a visible reason and the rule stays', function (): void {
    [$organization, $event, , $admin] = formScenario();
    [$track, $call] = rsrScope($organization, $event);
    $gone = rsrReviewer($organization, $event);
    $stays = rsrReviewer($organization, $event);
    rsrRule($organization, $event, $gone, $track);
    rsrRule($organization, $event, $stays, $track);
    rsrSubmission($event, $track, $call);
    EventAssignment::query()->withoutGlobalScopes()->where('user_id', $gone->id)->delete();
    Queue::fake();

    $report = app(SyncScopedAssignments::class)->handle($admin, $event);

    expect($report->assignedCount())->toBe(1)
        ->and($report->skippedFor(ReviewAssignmentSkipReason::ReviewerUnavailable))->toHaveCount(1)
        ->and($report->skippedFor(ReviewAssignmentSkipReason::ReviewerUnavailable)[0]->reviewerId)->toBe($gone->id)
        ->and(rsrRuleCount())->toBe(2);
});

it('CA-011-42: only a member with review_assignments.manage can run the manual sync, and the system run needs no person', function (): void {
    [$organization, $event] = formScenario();
    [$track, $call] = rsrScope($organization, $event);
    $reviewer = rsrReviewer($organization, $event);
    rsrRule($organization, $event, $reviewer, $track);
    rsrSubmission($event, $track, $call);
    Queue::fake();

    expect(fn () => app(SyncScopedAssignments::class)->handle($reviewer, $event))->toThrow(AuthorizationException::class)
        ->and(rsrAssignmentCount())->toBe(0)
        ->and(app(SyncScopedAssignments::class)->handle(null, $event)->assignedCount())->toBe(1);
});

it('CA-011-43: removing a rule keeps the assignments already created and later proposals are no longer assigned', function (): void {
    [$organization, $event, , $admin] = formScenario();
    [$track, $call] = rsrScope($organization, $event);
    $reviewer = rsrReviewer($organization, $event);
    $existing = rsrSubmission($event, $track, $call);
    Queue::fake();
    app(AssignReviewers::class)->handle($admin, $event, ['scope' => 'track', 'track_id' => $track->id, 'reviewer_ids' => [$reviewer->id], 'save_rule' => true]);
    $rule = CurrentOrganization::run($organization, fn () => ReviewScopeRule::query()->sole());

    app(RemoveScopeRule::class)->handle($admin, $event, ['rule_id' => $rule->id]);
    $arrived = rsrSubmission($event, $track, $call, $existing);
    (new SyncScopedAssignmentsJob($organization->id, $arrived->id))->handle(app(SyncScopedAssignments::class));

    CurrentOrganization::run($organization, function () use ($existing, $arrived, $admin): void {
        expect(ReviewAssignment::query()->where('submission_id', $existing->id)->count())->toBe(1)
            ->and(ReviewAssignment::query()->where('submission_id', $arrived->id)->exists())->toBeFalse()
            ->and(ReviewScopeRule::query()->count())->toBe(0);

        $log = Activity::query()->where('event', 'review_scope_rule_removed')->sole();
        expect($log->causer_id)->toBe($admin->id);
    });
});

it('CA-011-43: only a member with review_assignments.manage removes a rule; a foreign or unknown rule answers 404', function (): void {
    [$organization, $event] = formScenario();
    [$track] = rsrScope($organization, $event);
    $reviewer = rsrReviewer($organization, $event);
    $rule = rsrRule($organization, $event, $reviewer, $track);
    [$otherOrganization, $otherEvent, , $otherAdmin] = formScenario();
    [$otherTrack] = rsrScope($otherOrganization, $otherEvent);
    $foreignRule = rsrRule($otherOrganization, $otherEvent, rsrReviewer($otherOrganization, $otherEvent), $otherTrack);
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);

    expect(fn () => app(RemoveScopeRule::class)->handle($reviewer, $event, ['rule_id' => $rule->id]))->toThrow(AuthorizationException::class)
        ->and(rsrRuleCount())->toBe(2)
        ->and(fn () => app(RemoveScopeRule::class)->handle($admin, $event, ['rule_id' => $foreignRule->id]))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(RemoveScopeRule::class)->handle($admin, $event, ['rule_id' => (string) Str::uuid()]))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(RemoveScopeRule::class)->handle($otherAdmin, $event, ['rule_id' => $rule->id]))->toThrow(AuthorizationException::class)
        ->and(rsrRuleCount())->toBe(2);
});

it('CA-011-43: a user without the permission cannot store a rule by assigning a scope', function (): void {
    [$organization, $event] = formScenario();
    [$track, $call] = rsrScope($organization, $event);
    rsrSubmission($event, $track, $call);
    $reviewer = rsrReviewer($organization, $event);

    expect(fn () => app(AssignReviewers::class)->handle($reviewer, $event, [
        'scope' => 'track', 'track_id' => $track->id, 'reviewer_ids' => [$reviewer->id], 'save_rule' => true,
    ]))->toThrow(AuthorizationException::class)
        ->and(rsrRuleCount())->toBe(0);
});

it('the database rejects a repeated rule, a scope from another event and an inconsistent scope type', function (): void {
    [$organization, $event] = formScenario();
    [$track, $call] = rsrScope($organization, $event);
    $otherEvent = Event::factory()->create(['organization_id' => $organization->id]);
    [$foreignTrack] = rsrScope($organization, $otherEvent);
    $reviewer = rsrReviewer($organization, $event);
    $rule = rsrRule($organization, $event, $reviewer, $track);

    $row = fn (array $overrides): array => [
        'id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'event_id' => $event->id,
        'reviewer_id' => $reviewer->id, 'scope_type' => 'track', 'track_id' => $track->id, 'call_id' => null,
        'created_by' => $rule->created_by, 'created_at' => now(), 'updated_at' => now(), ...$overrides,
    ];

    foreach ([
        'repeated rule' => [],
        'track of another event' => ['track_id' => $foreignTrack->id],
        'track type with a call' => ['track_id' => null, 'call_id' => $call->id],
        'both scopes' => ['call_id' => $call->id],
        'no scope' => ['track_id' => null],
    ] as $case => $overrides) {
        expect(fn () => DB::transaction(fn () => DB::table('review_assignment_scopes')->insert($row($overrides))))
            ->toThrow(QueryException::class, '', $case);
    }

    expect(rsrRuleCount())->toBe(1);
});

it('the database keeps the organization of a rule immutable and rejects a reviewer outside the organization', function (): void {
    [$organization, $event] = formScenario();
    [$track] = rsrScope($organization, $event);
    $rule = rsrRule($organization, $event, rsrReviewer($organization, $event), $track);
    $outsider = User::factory()->create();

    expect(fn () => DB::transaction(fn () => DB::table('review_assignment_scopes')->where('id', $rule->id)->update(['organization_id' => Organization::factory()->create()->id])))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('review_assignment_scopes')->insert([
            'id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'event_id' => $event->id, 'reviewer_id' => $outsider->id,
            'scope_type' => 'track', 'track_id' => $track->id, 'created_by' => $rule->created_by, 'created_at' => now(), 'updated_at' => now(),
        ])))->toThrow(QueryException::class);
});
