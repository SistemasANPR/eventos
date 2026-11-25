<?php

declare(strict_types=1);

use App\Actions\Reviews\DeclareConflictOfInterest;
use App\Actions\Reviews\SubmitReview;
use App\Enums\OrganizationRole;
use App\Enums\ReviewAssignmentStatus;
use App\Filament\Resources\Events\Pages\ManageEventSubmissions;
use App\Filament\Reviewer\Resources\AssignedSubmissions\Pages\EvaluateSubmission;
use App\Filament\Reviewer\Resources\AssignedSubmissions\Pages\ListAssignedSubmissions;
use App\Models\Event;
use App\Models\EventAssignment;
use App\Models\Organization;
use App\Models\Review;
use App\Models\ReviewAssignment;
use App\Models\Rubric;
use App\Models\RubricCriterion;
use App\Models\Submission;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Memberships;
use Tests\Support\TwoFactor;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/form-helpers.php';

/*
 * Plan 011, F5 (H-011-4): declarar conflicto de interés (CA-011-17). La declaración la hace el evaluador dueño
 * de la asignación; no hay permiso nuevo: basta la Policy por propiedad (`ReviewPolicy::declareConflict`).
 */

beforeEach(function (): void {
    $this->withoutVite();
    config(['two_factor.required' => true]);
});

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/** @return array{organization: Organization, event: Event, reviewer: User, base: Submission, criterion: RubricCriterion} */
function cfScenario(): array
{
    $organization = Memberships::organization();
    $event = Event::factory()->create(['organization_id' => $organization->id]);
    $base = Submission::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id]);
    $rubric = Rubric::factory()->published()->create([
        'organization_id' => $organization->id, 'event_id' => $event->id, 'submission_type_id' => $base->submission_type_id,
    ]);
    $criterion = RubricCriterion::factory()->create(['organization_id' => $organization->id, 'rubric_id' => $rubric->id, 'label' => ['es' => 'Relevancia']]);
    $reviewer = cfReviewer($organization, $event);

    return compact('organization', 'event', 'reviewer', 'base', 'criterion');
}

function cfReviewer(Organization $organization, Event $event): User
{
    $user = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);
    TwoFactor::enable($user);
    EventAssignment::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id, 'user_id' => $user->id]);

    return $user->fresh();
}

function cfAssign(User $reviewer, Submission $submission, ReviewAssignmentStatus $status = ReviewAssignmentStatus::Pending): ReviewAssignment
{
    return ReviewAssignment::factory()->create([
        'organization_id' => $submission->organization_id,
        'event_id' => $submission->event_id,
        'submission_id' => $submission->id,
        'reviewer_id' => $reviewer->id,
        'status' => $status,
    ]);
}

function cfStatusOf(ReviewAssignment $assignment): ReviewAssignmentStatus
{
    return CurrentOrganization::run(Organization::query()->findOrFail($assignment->organization_id), fn (): ReviewAssignmentStatus => ReviewAssignment::query()->findOrFail($assignment->id)->status);
}

function cfPanel(Organization $organization, User $user): void
{
    Auth::login($user);
    Filament::setCurrentPanel('reviewer');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);
}

/** @return Testable<ListAssignedSubmissions> */
function cfList(Organization $organization, User $user): Testable
{
    cfPanel($organization, $user);

    return Livewire::test(ListAssignedSubmissions::class);
}

/** @return Testable<EvaluateSubmission> */
function cfEvaluate(Organization $organization, User $user, ReviewAssignment $assignment): Testable
{
    cfPanel($organization, $user);

    return Livewire::test(EvaluateSubmission::class, ['record' => $assignment->id]);
}

it('CA-011-17: declaring a conflict moves the assignment to declined_conflict, audited, and it can no longer be graded', function (): void {
    $scenario = cfScenario();
    $assignment = cfAssign($scenario['reviewer'], $scenario['base']);

    $result = app(DeclareConflictOfInterest::class)->handle($scenario['reviewer'], $assignment, ['confirmed' => true]);

    expect($result->status)->toBe(ReviewAssignmentStatus::DeclinedConflict)
        ->and(cfStatusOf($assignment))->toBe(ReviewAssignmentStatus::DeclinedConflict);

    $audit = Activity::query()->where('event', 'review_conflict_declared')->sole();
    expect($audit->causer_id)->toBe($scenario['reviewer']->id)
        ->and($audit->subject_id)->toBe($assignment->id)
        ->and($audit->properties['submission_id'])->toBe($scenario['base']->id)
        ->and($audit->properties['old']['status'])->toBe('pending')
        ->and($audit->properties['new']['status'])->toBe('declined_conflict');

    // No puede calificarla: ni la Policy ni la Action lo permiten.
    CurrentOrganization::set($scenario['organization']);
    $fresh = ReviewAssignment::query()->findOrFail($assignment->id);
    expect(Gate::forUser($scenario['reviewer'])->allows('submit', [Review::class, $fresh]))->toBeFalse();
    expect(fn () => app(SubmitReview::class)->handle($scenario['reviewer'], $fresh, [$scenario['criterion']->id => 4]))
        ->toThrow(AuthorizationException::class);
});

it('CA-011-17: an evaluation in progress keeps its draft and is declared as a conflict', function (): void {
    $scenario = cfScenario();
    $assignment = cfAssign($scenario['reviewer'], $scenario['base'], ReviewAssignmentStatus::InProgress);

    app(DeclareConflictOfInterest::class)->handle($scenario['reviewer'], $assignment, ['confirmed' => true]);

    expect(cfStatusOf($assignment))->toBe(ReviewAssignmentStatus::DeclinedConflict);
});

it('CA-011-17: a submitted evaluation cannot be declared as a conflict: its results are asked to be cancelled by the organizers', function (): void {
    $scenario = cfScenario();
    $assignment = cfAssign($scenario['reviewer'], $scenario['base'], ReviewAssignmentStatus::Submitted);

    expect(fn () => app(DeclareConflictOfInterest::class)->handle($scenario['reviewer'], $assignment, ['confirmed' => true]))
        ->toThrow(AuthorizationException::class)
        ->and(cfStatusOf($assignment))->toBe(ReviewAssignmentStatus::Submitted)
        ->and(Activity::query()->where('event', 'review_conflict_declared')->count())->toBe(0);
});

it('CA-011-17: only the owner of a live assignment declares: another reviewer, a cancelled one and one already declared are denied', function (): void {
    $scenario = cfScenario();
    $other = cfReviewer($scenario['organization'], $scenario['event']);
    $mine = cfAssign($scenario['reviewer'], $scenario['base']);
    $cancelled = cfAssign($scenario['reviewer'], Submission::factory()->create([
        'organization_id' => $scenario['organization']->id, 'event_id' => $scenario['event']->id,
        'call_for_submissions_id' => $scenario['base']->call_for_submissions_id, 'submission_type_id' => $scenario['base']->submission_type_id,
        'form_definition_id' => $scenario['base']->form_definition_id, 'track_id' => $scenario['base']->track_id,
        'privacy_notice_version_id' => $scenario['base']->privacy_notice_version_id,
    ]), ReviewAssignmentStatus::Cancelled);
    $action = app(DeclareConflictOfInterest::class);

    // Otro evaluador del mismo evento no puede declarar por ti.
    expect(fn () => $action->handle($other, $mine, ['confirmed' => true]))->toThrow(AuthorizationException::class)
        ->and(cfStatusOf($mine))->toBe(ReviewAssignmentStatus::Pending);

    expect(fn () => $action->handle($scenario['reviewer'], $cancelled, ['confirmed' => true]))->toThrow(AuthorizationException::class);

    // Declarar dos veces: la segunda se niega y no duplica la auditoría.
    $action->handle($scenario['reviewer'], $mine, ['confirmed' => true]);
    expect(fn () => $action->handle($scenario['reviewer'], $mine, ['confirmed' => true]))->toThrow(AuthorizationException::class)
        ->and(Activity::query()->where('event', 'review_conflict_declared')->count())->toBe(1);
});

it('CA-011-17: a reviewer of another organization cannot declare a conflict on an assignment that is not theirs', function (): void {
    $scenario = cfScenario();
    $assignment = cfAssign($scenario['reviewer'], $scenario['base']);
    $otherOrganization = Memberships::organization();
    $otherEvent = Event::factory()->create(['organization_id' => $otherOrganization->id]);
    $stranger = cfReviewer($otherOrganization, $otherEvent);

    expect(fn () => app(DeclareConflictOfInterest::class)->handle($stranger, $assignment, ['confirmed' => true]))
        ->toThrow(AuthorizationException::class)
        ->and(cfStatusOf($assignment))->toBe(ReviewAssignmentStatus::Pending);
});

it('CA-011-17: the declaration needs explicit confirmation and nothing changes without it', function (): void {
    $scenario = cfScenario();
    $assignment = cfAssign($scenario['reviewer'], $scenario['base']);

    expect(fn () => app(DeclareConflictOfInterest::class)->handle($scenario['reviewer'], $assignment, []))->toThrow(ValidationException::class)
        ->and(fn () => app(DeclareConflictOfInterest::class)->handle($scenario['reviewer'], $assignment, ['confirmed' => false]))->toThrow(ValidationException::class)
        ->and(cfStatusOf($assignment))->toBe(ReviewAssignmentStatus::Pending);
});

it('CA-011-17: the Declare conflict button is in the list and in the detail, only where it applies, and asks for confirmation', function (): void {
    $scenario = cfScenario();
    $pending = cfAssign($scenario['reviewer'], $scenario['base']);
    $declared = cfAssign($scenario['reviewer'], Submission::factory()->create([
        'organization_id' => $scenario['organization']->id, 'event_id' => $scenario['event']->id,
        'call_for_submissions_id' => $scenario['base']->call_for_submissions_id, 'submission_type_id' => $scenario['base']->submission_type_id,
        'form_definition_id' => $scenario['base']->form_definition_id, 'track_id' => $scenario['base']->track_id,
        'privacy_notice_version_id' => $scenario['base']->privacy_notice_version_id,
    ]), ReviewAssignmentStatus::DeclinedConflict);

    cfList($scenario['organization'], $scenario['reviewer'])
        ->assertTableActionVisible('declareConflict', $pending)
        ->assertTableActionHidden('declareConflict', $declared);

    cfEvaluate($scenario['organization'], $scenario['reviewer'], $pending)->assertActionVisible('declareConflict');
    cfEvaluate($scenario['organization'], $scenario['reviewer'], $declared)->assertActionHidden('declareConflict');
});

it('CA-011-17: confirming the button in the list and in the detail declares the conflict', function (): void {
    $scenario = cfScenario();
    $fromList = cfAssign($scenario['reviewer'], $scenario['base']);
    $fromDetail = cfAssign($scenario['reviewer'], Submission::factory()->create([
        'organization_id' => $scenario['organization']->id, 'event_id' => $scenario['event']->id,
        'call_for_submissions_id' => $scenario['base']->call_for_submissions_id, 'submission_type_id' => $scenario['base']->submission_type_id,
        'form_definition_id' => $scenario['base']->form_definition_id, 'track_id' => $scenario['base']->track_id,
        'privacy_notice_version_id' => $scenario['base']->privacy_notice_version_id,
    ]));

    cfList($scenario['organization'], $scenario['reviewer'])->callTableAction('declareConflict', $fromList)->assertNotified();
    cfEvaluate($scenario['organization'], $scenario['reviewer'], $fromDetail)->callAction('declareConflict')->assertNotified();

    expect(cfStatusOf($fromList))->toBe(ReviewAssignmentStatus::DeclinedConflict)
        ->and(cfStatusOf($fromDetail))->toBe(ReviewAssignmentStatus::DeclinedConflict);

    // El detalle de una asignación con conflicto declarado se consulta, sin formulario ni el botón.
    cfEvaluate($scenario['organization'], $scenario['reviewer'], $fromDetail)
        ->assertSee(__('reviewer.evaluate.cannot.conflict'))
        ->assertDontSee(__('reviewer.evaluate.submit'));
});

it('CA-011-17: the organizers see the declared conflicts in the proposals list and the coverage counts without them', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    $submission = Submission::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id]);
    $reviewer = cfReviewer($organization, $event);
    $quiet = Submission::factory()->create([
        'organization_id' => $organization->id, 'event_id' => $event->id,
        'call_for_submissions_id' => $submission->call_for_submissions_id, 'submission_type_id' => $submission->submission_type_id,
        'form_definition_id' => $submission->form_definition_id, 'track_id' => $submission->track_id,
        'privacy_notice_version_id' => $submission->privacy_notice_version_id,
    ]);
    ReviewAssignment::factory()->status(ReviewAssignmentStatus::DeclinedConflict)->create([
        'organization_id' => $organization->id, 'event_id' => $event->id, 'submission_id' => $submission->id, 'reviewer_id' => $reviewer->id,
    ]);

    Auth::login($organizer);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    Livewire::test(ManageEventSubmissions::class, ['record' => $event->id])
        ->assertSee(__('reviewer_integrity.organizer.conflicts', ['count' => 1]))
        // El conflicto no cuenta para la cobertura: 0 evaluadores activos.
        ->assertTableColumnStateSet('reviewers', '0/2', $submission)
        ->assertTableColumnStateSet('reviewers', '0/2', $quiet);
});
