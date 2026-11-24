<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\ReviewAssignmentSource;
use App\Enums\ReviewAssignmentStatus;
use App\Filament\Resources\Events\Pages\ManageEventAssignmentRules;
use App\Filament\Resources\Events\Pages\ManageEventReviewers;
use App\Filament\Resources\Events\Pages\ManageEventSubmissions;
use App\Jobs\SendTransactionalMail;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\EventAssignment;
use App\Models\Organization;
use App\Models\ReviewAssignment;
use App\Models\ReviewScopeRule;
use App\Models\Rubric;
use App\Models\Submission;
use App\Models\Track;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Component;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/form-helpers.php';

/*
 * Plan 011, F3b: la pantalla «Evaluadores» del evento (vista por evaluador, cobertura, invitación, reglas de
 * alcance). Delgada: las reglas y la autorización están en las Actions (ReviewScopeRuleTest y
 * ReviewerInvitationTest); aquí se comprueba lo que la pantalla muestra y que invoca esas Actions.
 */

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/**
 * @param  class-string  $page
 * @return Testable<Component>
 */
function rcPage(string $page, Organization $organization, User $user, Event $event): Testable
{
    Auth::login($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    return Livewire::test($page, ['record' => $event->id]);
}

function rcReviewer(Organization $organization, Event $event): User
{
    $user = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);
    EventAssignment::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id, 'user_id' => $user->id]);

    return $user;
}

/** @param  array<string, mixed>  $overrides */
function rcSubmission(Event $event, ?Submission $base = null, array $overrides = []): Submission
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

it('CA-011-48: the by-reviewer view shows each reviewer with the submissions assigned and the ones already sent', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    $busy = rcReviewer($organization, $event);
    $idle = rcReviewer($organization, $event);
    $first = rcSubmission($event);
    $second = rcSubmission($event, $first);
    $third = rcSubmission($event, $first);
    foreach ([[$first, ReviewAssignmentStatus::Submitted], [$second, ReviewAssignmentStatus::Pending], [$third, ReviewAssignmentStatus::Cancelled]] as [$submission, $status]) {
        ReviewAssignment::factory()->status($status)->create([
            'organization_id' => $organization->id, 'event_id' => $event->id, 'submission_id' => $submission->id, 'reviewer_id' => $busy->id,
        ]);
    }

    rcPage(ManageEventReviewers::class, $organization, $organizer, $event)
        ->assertSuccessful()
        ->assertSee($busy->name)
        ->assertSee($idle->name)
        // 2 activas (la cancelada no cuenta), 1 enviada.
        ->assertSee(__('review_assignments.reviewers.progress', ['done' => 1, 'total' => 2]))
        ->assertSee(__('review_assignments.reviewers.no_assignments'));
});

it('CA-011-48: a scope with fewer reviewers than min_reviewers is marked as incomplete coverage, and a covered one is not', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    $short = Track::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id, 'name' => ['es' => 'Eje corto']]);
    $covered = Track::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id, 'name' => ['es' => 'Eje cubierto']]);
    $call = CallForSubmissions::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id]);
    $inShort = rcSubmission($event, null, ['track_id' => $short->id, 'call_for_submissions_id' => $call->id]);
    $inCovered = rcSubmission($event, $inShort, ['track_id' => $covered->id]);
    Rubric::factory()->published()->create([
        'organization_id' => $organization->id, 'event_id' => $event->id, 'submission_type_id' => $inShort->submission_type_id, 'min_reviewers' => 1,
    ]);
    ReviewAssignment::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id, 'submission_id' => $inCovered->id]);

    rcPage(ManageEventReviewers::class, $organization, $organizer, $event)
        ->assertSee(__('review_assignments.columns.coverage_incomplete'))
        ->assertSee('Eje corto')
        ->assertDontSee('Eje cubierto');
});

it('CA-011-48: with every scope covered the screen says so instead of marking anything', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);

    rcPage(ManageEventReviewers::class, $organization, $organizer, $event)
        ->assertSee(__('review_assignments.reviewers.coverage_ok'))
        ->assertDontSee(__('review_assignments.columns.coverage_incomplete'));
});

it('CA-011-48: only who can assign sees the screen: an organizer does, a reviewer does not', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    $reviewer = rcReviewer($organization, $event);
    [, , $foreignOrganizer] = formScenario(OrganizationRole::Organizer);

    $this->actingAs($organizer)->get("/admin/{$organization->slug}/events/{$event->id}/reviewers")->assertOk();
    $this->actingAs($organizer)->get("/admin/{$organization->slug}/events/{$event->id}/assignment-rules")->assertOk();
    foreach (['reviewers', 'assignment-rules'] as $page) {
        $this->actingAs($reviewer)->get("/admin/{$organization->slug}/events/{$event->id}/{$page}")->assertForbidden();
        expect($this->actingAs($foreignOrganizer)->get("/admin/{$organization->slug}/events/{$event->id}/{$page}")->status())->toBeIn([403, 404]);
    }
});

it('CA-011-49: choosing a member with no event assignment assigns nothing and offers to invite them', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    $submission = rcSubmission($event);
    $outsider = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);
    Queue::fake();

    Auth::login($organizer);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    Livewire::test(ManageEventSubmissions::class, ['record' => $event->id])
        ->callTableBulkAction('assignReviewers', [$submission], ['reviewer_ids' => [$outsider->id]])
        ->assertNotified(__('review_assignments.invite.needed_title'));

    expect(ReviewAssignment::query()->withoutGlobalScopes()->count())->toBe(0);
    Queue::assertNotPushed(SendTransactionalMail::class);
});

it('CA-011-49: after the invitation exists the same member can be assigned', function (): void {
    config(['two_factor.required' => true]);
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    $submission = rcSubmission($event);
    Queue::fake();

    rcPage(ManageEventReviewers::class, $organization, $organizer, $event)
        ->callAction('inviteReviewer', ['name' => 'Luis Ramírez', 'email' => 'luis@example.test'])
        ->assertHasNoActionErrors()
        ->assertNotified(__('review_assignments.invite.sent'));
    $reviewer = User::query()->where('email', 'luis@example.test')->firstOrFail();

    expect(EventAssignment::query()->withoutGlobalScopes()->where('user_id', $reviewer->id)->count())->toBe(1);

    Livewire::test(ManageEventSubmissions::class, ['record' => $event->id])
        ->callTableBulkAction('assignReviewers', [$submission], ['reviewer_ids' => [$reviewer->id]])
        ->assertNotified(__('review_assignments.report.title'));

    expect(ReviewAssignment::query()->withoutGlobalScopes()->where('reviewer_id', $reviewer->id)->count())->toBe(1);
});

it('CA-011-34: with the mandatory 2FA off the invite action shows the cause and creates nothing', function (): void {
    config(['two_factor.required' => false]);
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);

    rcPage(ManageEventReviewers::class, $organization, $organizer, $event)
        ->callAction('inviteReviewer', ['name' => 'Luis', 'email' => 'luis@example.test'])
        ->assertNotified(__('review_assignments.report.failed'));

    expect(User::query()->where('email', 'luis@example.test')->exists())->toBeFalse();
});

it('CA-011-33: the invite action is not offered to who lacks reviewers.invite', function (): void {
    [$organization, $event] = formScenario(OrganizationRole::Organizer);
    $reviewer = rcReviewer($organization, $event);

    Auth::login($reviewer);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    // El evaluador ni siquiera entra a la pantalla: se comprueba también la visibilidad de la acción en la lista.
    expect(Gate::allows('inviteReviewer', [EventAssignment::class, $event]))->toBeFalse();
});

it('CA-011-38: assigning a track from the screen stores the rule, and the rules screen lists it', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    $first = rcSubmission($event);
    $reviewer = rcReviewer($organization, $event);
    Queue::fake();

    Auth::login($organizer);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);
    Livewire::test(ManageEventSubmissions::class, ['record' => $event->id])
        ->callAction('assignReviewersByScope', ['scope' => 'track', 'track_id' => $first->track_id, 'reviewer_ids' => [$reviewer->id]])
        ->assertHasNoActionErrors();

    $rule = ReviewScopeRule::query()->sole();
    expect($rule->reviewer_id)->toBe($reviewer->id);

    Livewire::test(ManageEventAssignmentRules::class, ['record' => $event->id])
        ->assertCanSeeTableRecords([$rule])
        ->assertSee($reviewer->name);
});

it('CA-011-43: the rules screen removes a rule and keeps the assignments already made', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    $submission = rcSubmission($event);
    $reviewer = rcReviewer($organization, $event);
    ReviewAssignment::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id, 'submission_id' => $submission->id, 'reviewer_id' => $reviewer->id]);
    $rule = ReviewScopeRule::factory()->create([
        'organization_id' => $organization->id, 'event_id' => $event->id, 'reviewer_id' => $reviewer->id, 'track_id' => $submission->track_id,
    ]);

    rcPage(ManageEventAssignmentRules::class, $organization, $organizer, $event)
        ->callTableAction('removeScopeRule', $rule)
        ->assertNotified(__('review_assignments.rules.removed'));

    expect(ReviewScopeRule::query()->count())->toBe(0)
        ->and(ReviewAssignment::query()->where('submission_id', $submission->id)->count())->toBe(1);
});

it('CA-011-42: the button «Asignar propuestas nuevas» applies the rules without sending mail', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    $submission = rcSubmission($event);
    $reviewer = rcReviewer($organization, $event);
    ReviewScopeRule::factory()->create([
        'organization_id' => $organization->id, 'event_id' => $event->id, 'reviewer_id' => $reviewer->id, 'track_id' => $submission->track_id,
    ]);
    Queue::fake();

    rcPage(ManageEventReviewers::class, $organization, $organizer, $event)
        ->callAction('syncScopedAssignments')
        ->assertNotified(__('review_assignments.report.title'));

    $assignment = ReviewAssignment::query()->sole();
    expect($assignment->source)->toBe(ReviewAssignmentSource::Scope);
    Queue::assertNotPushed(SendTransactionalMail::class);
});
