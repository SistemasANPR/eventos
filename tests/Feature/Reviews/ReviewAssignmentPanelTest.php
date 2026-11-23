<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\ReviewAssignmentSource;
use App\Enums\ReviewAssignmentStatus;
use App\Enums\SubmissionStatus;
use App\Filament\Resources\Events\Pages\ManageEventSubmissions;
use App\Filament\Resources\Submissions\Pages\ViewSubmission;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\EventAssignment;
use App\Models\Organization;
use App\Models\ReviewAssignment;
use App\Models\Rubric;
use App\Models\Submission;
use App\Models\SubmissionParticipant;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/form-helpers.php';

/*
 * Plan 011, F3a: las acciones del panel de organizadores. Delgadas: la regla y la autorización están en
 * `AssignReviewers` y `RemoveReviewAssignment` (ReviewAssignmentTest); aquí se comprueba que la pantalla
 * las invoca, muestra el informe y no deja asignar sin permiso.
 */

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/** @return Testable<ManageEventSubmissions> */
function raSubmissionList(Organization $organization, User $user, Event $event): Testable
{
    Auth::login($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    return Livewire::test(ManageEventSubmissions::class, ['record' => $event->id]);
}

/** @return Testable<ViewSubmission> */
function raSubmissionDetail(Organization $organization, User $user, Event $event, Submission $submission): Testable
{
    Auth::login($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    return Livewire::test(ViewSubmission::class, ['record' => $submission->id, 'parentRecord' => $event]);
}

function raPanelReviewer(Organization $organization, Event $event, ?string $email = null): User
{
    $user = Memberships::attach(User::factory()->create($email === null ? [] : ['email' => $email]), $organization, OrganizationRole::Reviewer);
    EventAssignment::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id, 'user_id' => $user->id]);

    return $user;
}

/** @param  array<string, mixed>  $overrides */
function raPanelSubmission(Event $event, ?Submission $base = null, array $overrides = []): Submission
{
    $shared = $base === null ? [] : [
        'call_for_submissions_id' => $base->call_for_submissions_id,
        'submission_type_id' => $base->submission_type_id,
        'form_definition_id' => $base->form_definition_id,
        'track_id' => $base->track_id,
        'privacy_notice_version_id' => $base->privacy_notice_version_id,
    ];

    return Submission::factory()->create(['organization_id' => $event->organization_id, 'event_id' => $event->id, ...$shared, ...$overrides]);
}

it('CA-011-39: the bulk action assigns only the marked submissions, shows the report and creates no rule', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    $marked = raPanelSubmission($event);
    $second = raPanelSubmission($event, $marked);
    $left = raPanelSubmission($event, $marked);
    $reviewer = raPanelReviewer($organization, $event);
    Queue::fake();

    raSubmissionList($organization, $organizer, $event)
        ->callTableBulkAction('assignReviewers', [$marked, $second], ['reviewer_ids' => [$reviewer->id]])
        ->assertHasNoTableBulkActionErrors()
        ->assertNotified(__('review_assignments.report.title'));

    CurrentOrganization::run($organization, function () use ($marked, $second, $left): void {
        expect(ReviewAssignment::query()->pluck('submission_id')->sort()->values()->all())->toBe(collect([$marked->id, $second->id])->sort()->values()->all())
            ->and(ReviewAssignment::query()->pluck('source')->unique()->all())->toBe([ReviewAssignmentSource::Manual])
            ->and(ReviewAssignment::query()->where('submission_id', $left->id)->exists())->toBeFalse();
    });
});

it('CA-011-41: the report on screen lists the skipped pair with its reason', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    $conflicted = raPanelSubmission($event, null, ['title' => 'Parques vivos']);
    SubmissionParticipant::factory()->create(['organization_id' => $organization->id, 'submission_id' => $conflicted->id, 'email' => 'autor@example.test']);
    $reviewer = raPanelReviewer($organization, $event, 'Autor@Example.test');
    Queue::fake();

    raSubmissionList($organization, $organizer, $event)
        ->callTableBulkAction('assignReviewers', [$conflicted], ['reviewer_ids' => [$reviewer->id]])
        ->assertNotified();

    expect(ReviewAssignment::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('CA-011-38: the header action assigns a whole call by expansion', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    $call = CallForSubmissions::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id]);
    $first = raPanelSubmission($event, null, ['call_for_submissions_id' => $call->id]);
    $second = raPanelSubmission($event, $first);
    $reviewer = raPanelReviewer($organization, $event);
    Queue::fake();

    raSubmissionList($organization, $organizer, $event)
        ->callAction('assignReviewersByScope', ['scope' => 'call', 'call_id' => $call->id, 'reviewer_ids' => [$reviewer->id]])
        ->assertHasNoActionErrors()
        ->assertNotified(__('review_assignments.report.title'));

    CurrentOrganization::run($organization, fn () => expect(ReviewAssignment::query()->whereIn('submission_id', [$first->id, $second->id])->count())->toBe(2));
});

it('CA-011-5: the header action assigns a whole track', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    $first = raPanelSubmission($event);
    $second = raPanelSubmission($event, $first);
    $reviewer = raPanelReviewer($organization, $event);
    Queue::fake();

    raSubmissionList($organization, $organizer, $event)
        ->callAction('assignReviewersByScope', ['scope' => 'track', 'track_id' => $first->track_id, 'reviewer_ids' => [$reviewer->id]])
        ->assertHasNoActionErrors();

    CurrentOrganization::run($organization, fn () => expect(ReviewAssignment::query()->whereIn('submission_id', [$first->id, $second->id])->count())->toBe(2));
});

it('CA-011-10: a user without a reviewer assignment cannot be offered or assigned from the screen', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    $submission = raPanelSubmission($event);
    $outsider = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);

    raSubmissionList($organization, $organizer, $event)
        ->callTableBulkAction('assignReviewers', [$submission], ['reviewer_ids' => [$outsider->id]]);

    expect(ReviewAssignment::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('CA-011-6: the list marks a submission below the rubric minimum as incomplete coverage', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    $short = raPanelSubmission($event);
    $full = raPanelSubmission($event, $short);
    Rubric::factory()->published()->create([
        'organization_id' => $organization->id, 'event_id' => $event->id, 'submission_type_id' => $short->submission_type_id, 'min_reviewers' => 1,
    ]);
    ReviewAssignment::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id, 'submission_id' => $full->id]);

    raSubmissionList($organization, $organizer, $event)
        ->assertSee('0/1')
        ->assertSee('1/1')
        ->assertSee(__('review_assignments.columns.coverage_incomplete'))
        ->assertTableColumnStateSet('reviewers', '1/1', $full)
        ->assertTableColumnStateSet('reviewers', '0/1', $short);
});

it('CA-011-6: a cancelled assignment does not count and a decided submission shows no coverage', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    $submission = raPanelSubmission($event);
    $decided = raPanelSubmission($event, $submission, ['status' => SubmissionStatus::Rejected]);
    Rubric::factory()->published()->create([
        'organization_id' => $organization->id, 'event_id' => $event->id, 'submission_type_id' => $submission->submission_type_id, 'min_reviewers' => 1,
    ]);
    ReviewAssignment::factory()->status(ReviewAssignmentStatus::Cancelled)->create(['organization_id' => $organization->id, 'event_id' => $event->id, 'submission_id' => $submission->id]);

    raSubmissionList($organization, $organizer, $event)
        ->assertTableColumnStateSet('reviewers', '0/1', $submission)
        ->assertTableColumnStateSet('reviewers', null, $decided);
});

it('CA-011-45: the detail removes a pending assignment and cancels one in progress', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    $submission = raPanelSubmission($event);
    $pending = ReviewAssignment::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id, 'submission_id' => $submission->id]);
    $started = ReviewAssignment::factory()->status(ReviewAssignmentStatus::InProgress)->create(['organization_id' => $organization->id, 'event_id' => $event->id, 'submission_id' => $submission->id]);

    raSubmissionDetail($organization, $organizer, $event, $submission)
        ->assertActionVisible('removeReviewer')
        ->callAction('removeReviewer', ['assignment_id' => $pending->id])
        ->assertHasNoActionErrors()
        ->callAction('removeReviewer', ['assignment_id' => $started->id])
        ->assertHasNoActionErrors()
        ->assertActionHidden('removeReviewer');

    CurrentOrganization::run($organization, fn () => expect(ReviewAssignment::query()->find($pending->id))->toBeNull()
        ->and(ReviewAssignment::query()->findOrFail($started->id)->status)->toBe(ReviewAssignmentStatus::Cancelled));
});

it('CA-011-46: the remove action stays hidden when the submission has no assignment', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    $submission = raPanelSubmission($event);

    raSubmissionDetail($organization, $organizer, $event, $submission)->assertActionHidden('removeReviewer');
});
