<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\ReviewAssignmentStatus;
use App\Filament\Resources\TeamMembers\TeamMemberResource;
use App\Filament\Reviewer\Resources\AssignedSubmissions\AssignedSubmissionResource;
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
use App\Models\SubmissionFile;
use App\Models\Track;
use App\Models\User;
use App\Support\Auth\PortalDestination;
use App\Support\Reviews\ReviewerAssignments;
use App\Support\Reviews\ReviewerDashboardUrl;
use App\Support\Reviews\ReviewerSummary;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Memberships;
use Tests\Support\TwoFactor;

uses(RefreshDatabase::class);

/*
 * Plan 011, F4: el panel `Reviewer`. Cada prueba usa organizaciones propias: la caché de permisos de Spatie
 * se comparte dentro del proceso.
 */

beforeEach(function (): void {
    $this->withoutVite();
    // En el entorno local la 2FA puede estar apagada por `.env`: estas pruebas fijan la exigencia vigente.
    config(['two_factor.required' => true]);
});

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/**
 * Una organización con un evento, un evaluador con 2FA confirmada y su asignación al evento.
 *
 * @return array{organization: Organization, event: Event, reviewer: User, base: Submission, rubric: Rubric, criterion: RubricCriterion}
 */
function rpScenario(): array
{
    $organization = Memberships::organization();
    $event = Event::factory()->create(['organization_id' => $organization->id]);
    $base = Submission::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id]);
    $rubric = Rubric::factory()->published()->create([
        'organization_id' => $organization->id,
        'event_id' => $event->id,
        'submission_type_id' => $base->submission_type_id,
    ]);
    $criterion = RubricCriterion::factory()->create(['organization_id' => $organization->id, 'rubric_id' => $rubric->id, 'label' => ['es' => 'Relevancia']]);
    $reviewer = rpReviewer($organization, $event);

    return compact('organization', 'event', 'reviewer', 'base', 'rubric', 'criterion');
}

function rpReviewer(Organization $organization, Event $event): User
{
    $user = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);
    TwoFactor::enable($user);
    EventAssignment::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id, 'user_id' => $user->id]);

    return $user->fresh();
}

/**
 * Una propuesta más del evento, que comparte convocatoria, tipo y aviso con la base (la factory los crea únicos).
 *
 * @param  array<string, mixed>  $overrides
 */
function rpSubmission(Event $event, Submission $base, array $overrides = []): Submission
{
    return Submission::factory()->create([
        'organization_id' => $event->organization_id,
        'event_id' => $event->id,
        'call_for_submissions_id' => $base->call_for_submissions_id,
        'submission_type_id' => $base->submission_type_id,
        'form_definition_id' => $base->form_definition_id,
        'track_id' => $base->track_id,
        'privacy_notice_version_id' => $base->privacy_notice_version_id,
        ...$overrides,
    ]);
}

/** @param  array<string, mixed>  $overrides */
function rpAssign(User $reviewer, Submission $submission, ReviewAssignmentStatus $status = ReviewAssignmentStatus::Pending, array $overrides = []): ReviewAssignment
{
    return ReviewAssignment::factory()->create([
        'organization_id' => $submission->organization_id,
        'event_id' => $submission->event_id,
        'submission_id' => $submission->id,
        'reviewer_id' => $reviewer->id,
        'status' => $status,
        ...$overrides,
    ]);
}

/** Entra al panel del evaluador en una prueba de Livewire. */
function rpPanel(Organization $organization, User $user): void
{
    Auth::login($user);
    Filament::setCurrentPanel('reviewer');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);
}

/** @return Testable<ListAssignedSubmissions> */
function rpList(Organization $organization, User $user): Testable
{
    rpPanel($organization, $user);

    return Livewire::test(ListAssignedSubmissions::class);
}

/** @return Testable<EvaluateSubmission> */
function rpEvaluate(Organization $organization, User $user, ReviewAssignment $assignment): Testable
{
    rpPanel($organization, $user);

    return Livewire::test(EvaluateSubmission::class, ['record' => $assignment->id]);
}

it('CA-011-16: a user with the reviewer role and 2FA gets 403 in the organizers panel and its resources', function (): void {
    $scenario = rpScenario();
    $organization = $scenario['organization'];

    $this->actingAs($scenario['reviewer'])->get("/admin/{$organization->slug}")->assertForbidden();
    $this->actingAs($scenario['reviewer'])->get(TeamMemberResource::getUrl('index', tenant: $organization))->assertForbidden();
    $this->actingAs($scenario['reviewer'])->get("/admin/{$organization->slug}/events")->assertForbidden();
    $this->actingAs($scenario['reviewer'])->get("/admin/{$organization->slug}/events/{$scenario['event']->id}/submissions")->assertForbidden();
});

it('CA-011-16: the reviewer panel is the portal of a reviewer and an organizer without the reviewer role does not enter it', function (): void {
    $scenario = rpScenario();
    $organization = $scenario['organization'];
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    TwoFactor::enable($admin);

    $this->actingAs($scenario['reviewer'])->get("/reviewer/{$organization->slug}")->assertOk()->assertSee(__('reviewer.dashboard.title'));
    $this->actingAs($admin->fresh())->get("/reviewer/{$organization->slug}")->assertForbidden();
    $this->actingAs($admin->fresh())->get("/admin/{$organization->slug}")->assertOk();

    // Quien es organizador y evaluador a la vez entra a los dos paneles.
    CurrentOrganization::run($organization, fn () => $admin->assignRole('reviewer'));
    $this->actingAs($admin->fresh())->get("/reviewer/{$organization->slug}")->assertOk();
    $this->actingAs($admin->fresh())->get("/admin/{$organization->slug}")->assertOk();
});

it('CA-011-16: a reviewer is sent to their own panel after signing in and a reviewer-only user has no other portal', function (): void {
    $scenario = rpScenario();

    expect(PortalDestination::home($scenario['reviewer']))->toBe(ReviewerDashboardUrl::for($scenario['organization']))
        ->and($scenario['reviewer']->homePanelId())->toBe('reviewer')
        ->and($scenario['reviewer']->canAccessPanelId('admin'))->toBeFalse()
        ->and($scenario['reviewer']->canAccessPanelId('reviewer'))->toBeTrue();
});

it('CA-011-16: the reviewer panel requires the second factor like the other panels', function (): void {
    $scenario = rpScenario();
    $withoutSecondFactor = Memberships::attach(User::factory()->create(), $scenario['organization'], OrganizationRole::Reviewer);
    $setUp = (string) Filament::getPanel('reviewer')->getSetUpRequiredMultiFactorAuthenticationUrl();

    $this->actingAs($withoutSecondFactor)->get("/reviewer/{$scenario['organization']->slug}")->assertRedirect($setUp);
    $this->actingAs($withoutSecondFactor)->get("/reviewer/{$scenario['organization']->slug}/assigned-submissions")->assertRedirect($setUp);
});

it('CA-011-16: the reviewer panel of another organization answers 404 and a deactivated reviewer is refused', function (): void {
    $scenario = rpScenario();
    $other = Memberships::organization();

    expect($this->actingAs($scenario['reviewer'])->get("/reviewer/{$other->slug}")->status())->toBeIn([403, 404]);

    $scenario['reviewer']->organizations()->updateExistingPivot($scenario['organization']->id, ['status' => 'deactivated', 'deactivated_at' => now()]);

    expect($this->actingAs($scenario['reviewer']->fresh())->get("/reviewer/{$scenario['organization']->slug}")->status())->toBeIn([403, 404]);
});

it('CA-011-11: a reviewer with two assignments in an event of ten sees only those two', function (): void {
    $scenario = rpScenario();
    $others = collect(range(1, 8))->map(fn (): Submission => rpSubmission($scenario['event'], $scenario['base']));
    $mine = [rpAssign($scenario['reviewer'], $scenario['base']), rpAssign($scenario['reviewer'], $others[0])];
    $otherReviewer = rpReviewer($scenario['organization'], $scenario['event']);
    $notMine = $others->skip(1)->map(fn (Submission $submission): ReviewAssignment => rpAssign($otherReviewer, $submission))->all();

    rpList($scenario['organization'], $scenario['reviewer'])
        ->assertCanSeeTableRecords($mine)
        ->assertCanNotSeeTableRecords($notMine)
        ->assertCountTableRecords(2);

    CurrentOrganization::run($scenario['organization'], fn () => expect(ReviewerAssignments::for($scenario['reviewer'])->count())->toBe(2));
});

it('CA-011-11: a reviewer who lost the event assignment sees none of its assignments and cannot open the list', function (): void {
    $scenario = rpScenario();
    $assignment = rpAssign($scenario['reviewer'], $scenario['base']);
    CurrentOrganization::run($scenario['organization'], fn () => EventAssignment::query()->where('user_id', $scenario['reviewer']->id)->delete());
    $slug = $scenario['organization']->slug;

    CurrentOrganization::run($scenario['organization'], fn () => expect(ReviewerAssignments::for($scenario['reviewer'])->count())->toBe(0));
    $this->actingAs($scenario['reviewer'])->get("/reviewer/{$slug}/assigned-submissions")->assertForbidden();
    $this->actingAs($scenario['reviewer'])->get("/reviewer/{$slug}/assigned-submissions/{$assignment->id}")->assertNotFound();
});

it('CA-011-12: a submission that is not assigned to the reviewer answers 404 by its identifier', function (): void {
    $scenario = rpScenario();
    $mine = rpAssign($scenario['reviewer'], $scenario['base']);
    $otherSubmission = rpSubmission($scenario['event'], $scenario['base']);
    $otherReviewer = rpReviewer($scenario['organization'], $scenario['event']);
    $notMine = rpAssign($otherReviewer, $otherSubmission);
    $cancelled = rpAssign($scenario['reviewer'], rpSubmission($scenario['event'], $scenario['base']), ReviewAssignmentStatus::Cancelled);
    $slug = $scenario['organization']->slug;

    $this->actingAs($scenario['reviewer'])->get("/reviewer/{$slug}/assigned-submissions/{$mine->id}")->assertOk();
    $this->actingAs($scenario['reviewer'])->get("/reviewer/{$slug}/assigned-submissions/{$notMine->id}")->assertNotFound();
    // Ni por el identificador de la propuesta ni por el de una asignación cancelada.
    $this->actingAs($scenario['reviewer'])->get("/reviewer/{$slug}/assigned-submissions/{$otherSubmission->id}")->assertNotFound();
    $this->actingAs($scenario['reviewer'])->get("/reviewer/{$slug}/assigned-submissions/{$cancelled->id}")->assertNotFound();
});

it('CA-011-28: an assignment of another organization or of an event the reviewer is not assigned to answers 404', function (): void {
    $scenario = rpScenario();
    $foreign = rpScenario();
    $foreignAssignment = rpAssign($foreign['reviewer'], $foreign['base']);
    $otherEvent = Event::factory()->create(['organization_id' => $scenario['organization']->id]);
    $otherEventSubmission = Submission::factory()->create(['organization_id' => $scenario['organization']->id, 'event_id' => $otherEvent->id]);
    $inOtherEvent = rpAssign($scenario['reviewer'], $otherEventSubmission);
    $slug = $scenario['organization']->slug;

    $this->actingAs($scenario['reviewer'])->get("/reviewer/{$slug}/assigned-submissions/{$foreignAssignment->id}")->assertNotFound();
    $this->actingAs($scenario['reviewer'])->get("/reviewer/{$slug}/assigned-submissions/{$inOtherEvent->id}")->assertNotFound();
});

it('CA-011-50: the dashboard shows the totals by state and whether the review period is open or closed', function (): void {
    $scenario = rpScenario();
    rpAssign($scenario['reviewer'], $scenario['base']);
    rpAssign($scenario['reviewer'], rpSubmission($scenario['event'], $scenario['base']));
    rpAssign($scenario['reviewer'], rpSubmission($scenario['event'], $scenario['base']), ReviewAssignmentStatus::InProgress);
    rpAssign($scenario['reviewer'], rpSubmission($scenario['event'], $scenario['base']), ReviewAssignmentStatus::Submitted);
    rpAssign($scenario['reviewer'], rpSubmission($scenario['event'], $scenario['base']), ReviewAssignmentStatus::DeclinedConflict);
    rpAssign($scenario['reviewer'], rpSubmission($scenario['event'], $scenario['base']), ReviewAssignmentStatus::Cancelled);

    CurrentOrganization::run($scenario['organization'], function () use ($scenario): void {
        expect(ReviewerSummary::counts($scenario['reviewer']))->toBe([
            'assigned' => 5, 'pending' => 2, 'in_progress' => 1, 'submitted' => 1, 'declined_conflict' => 1,
        ])->and(ReviewerSummary::periods($scenario['reviewer']))->toHaveCount(1)
            ->and(ReviewerSummary::periods($scenario['reviewer'])[0]['open'])->toBeTrue();
    });

    $html = $this->actingAs($scenario['reviewer'])->get("/reviewer/{$scenario['organization']->slug}")->assertOk()
        ->assertSee(__('reviewer.dashboard.period_open'))
        ->assertDontSee(__('reviewer.dashboard.period_closed_notice'))
        ->getContent();

    expect($html)->toMatch('/data-card="assigned".*?<dd[^>]*>\s*5\s*<\/dd>/s')
        ->and($html)->toMatch('/data-card="pending".*?<dd[^>]*>\s*2\s*<\/dd>/s')
        ->and($html)->toMatch('/data-card="declined_conflict".*?<dd[^>]*>\s*1\s*<\/dd>/s');

    $scenario['event']->forceFill(['review_period_closed_at' => now()])->save();

    $this->actingAs($scenario['reviewer'])->get("/reviewer/{$scenario['organization']->slug}")->assertOk()
        ->assertSee(__('reviewer.dashboard.period_closed_notice'));
});

it('CA-011-50: the dashboard counts only the own assignments of the reviewer', function (): void {
    $scenario = rpScenario();
    $other = rpReviewer($scenario['organization'], $scenario['event']);
    rpAssign($scenario['reviewer'], $scenario['base']);
    rpAssign($other, rpSubmission($scenario['event'], $scenario['base']));
    rpAssign($other, rpSubmission($scenario['event'], $scenario['base']));

    CurrentOrganization::run($scenario['organization'], fn () => expect(ReviewerSummary::counts($scenario['reviewer'])['assigned'])->toBe(1));
});

it('CA-011-51: the list is paginated with a cap of 100, pending first, and filters by state, track and call', function (): void {
    $scenario = rpScenario();
    $submitted = rpAssign($scenario['reviewer'], $scenario['base'], ReviewAssignmentStatus::Submitted, ['assigned_at' => now()->addDay()]);
    $inProgress = rpAssign($scenario['reviewer'], rpSubmission($scenario['event'], $scenario['base']), ReviewAssignmentStatus::InProgress, ['assigned_at' => now()->addHour()]);
    $pending = rpAssign($scenario['reviewer'], rpSubmission($scenario['event'], $scenario['base']), ReviewAssignmentStatus::Pending, ['assigned_at' => now()->subWeek()]);
    $otherTrack = Track::factory()->create(['organization_id' => $scenario['organization']->id, 'event_id' => $scenario['event']->id]);
    $inOtherTrack = rpAssign($scenario['reviewer'], rpSubmission($scenario['event'], $scenario['base'], ['track_id' => $otherTrack->id]), ReviewAssignmentStatus::Submitted);

    $list = rpList($scenario['organization'], $scenario['reviewer']);

    $list->assertCanSeeTableRecords([$pending, $inProgress, $submitted, $inOtherTrack->refresh()], inOrder: false)
        ->assertCanSeeTableRecords([$pending, $inProgress], inOrder: false);
    // Las pendientes van primero aunque sean las más antiguas, y las en curso antes que las enviadas.
    expect(array_slice($list->instance()->getTableRecords()->pluck('id')->all(), 0, 2))->toBe([$pending->id, $inProgress->id]);

    $list->filterTable('status', ReviewAssignmentStatus::Submitted->value)
        ->assertCanSeeTableRecords([$submitted, $inOtherTrack])
        ->assertCanNotSeeTableRecords([$pending, $inProgress]);

    $list->resetTableFilters()->filterTable('track', $otherTrack->id)
        ->assertCanSeeTableRecords([$inOtherTrack])
        ->assertCanNotSeeTableRecords([$pending, $inProgress, $submitted]);

    $list->resetTableFilters()->filterTable('call', $scenario['base']->call_for_submissions_id)
        ->assertCanSeeTableRecords([$pending, $inProgress, $submitted, $inOtherTrack]);

    expect(max($list->instance()->getTable()->getPaginationPageOptions()))->toBe(100)
        ->and($list->instance()->getTable()->getDefaultPaginationPageOption())->toBe(25);
});

it('CA-011-51: a filter value that is not one of the options of the reviewer returns nothing and does not fail', function (): void {
    $scenario = rpScenario();
    $mine = rpAssign($scenario['reviewer'], $scenario['base']);
    $foreignTrack = Track::factory()->create(['organization_id' => $scenario['organization']->id, 'event_id' => $scenario['event']->id]);

    rpList($scenario['organization'], $scenario['reviewer'])
        ->filterTable('track', $foreignTrack->id)
        ->assertCanNotSeeTableRecords([$mine])
        ->resetTableFilters()
        ->filterTable('call', 'no-es-un-uuid')
        ->assertCanNotSeeTableRecords([$mine]);
});

it('CA-011-51: the page size of the list cannot be raised above 100', function (): void {
    $scenario = rpScenario();
    rpAssign($scenario['reviewer'], $scenario['base']);

    $list = rpList($scenario['organization'], $scenario['reviewer'])->set('tableRecordsPerPage', 5000);

    expect($list->instance()->getTableRecordsPerPage())->toBeLessThanOrEqual(100);
});

it('CA-011-52: a reviewer without assignments sees an empty state on the dashboard and in the list', function (): void {
    $scenario = rpScenario();

    $this->actingAs($scenario['reviewer'])->get("/reviewer/{$scenario['organization']->slug}")->assertOk()
        ->assertSee(__('reviewer.dashboard.empty_heading'));

    rpList($scenario['organization'], $scenario['reviewer'])->assertSee(__('reviewer.list.empty_heading'));
});

it('CA-011-52: with the period closed the list warns it and the grading actions are not available', function (): void {
    $scenario = rpScenario();
    $assignment = rpAssign($scenario['reviewer'], $scenario['base']);
    $scenario['event']->forceFill(['review_period_closed_at' => now()])->save();

    rpList($scenario['organization'], $scenario['reviewer'])
        ->assertSee(__('reviewer.list.period_closed_notice', ['events' => (string) $scenario['event']->getTranslation('name', app()->getLocale(), true)]))
        ->assertCanSeeTableRecords([$assignment])
        ->assertTableActionHidden('review', $assignment);

    rpEvaluate($scenario['organization'], $scenario['reviewer'], $assignment)
        ->assertSee(__('reviewer.evaluate.cannot.period_closed'))
        ->assertDontSee(__('reviewer.evaluate.submit'));
});

it('CA-011-53: a cancelled assignment is not listed and one with a declared conflict has no grading action', function (): void {
    $scenario = rpScenario();
    $cancelled = rpAssign($scenario['reviewer'], $scenario['base'], ReviewAssignmentStatus::Cancelled);
    $conflict = rpAssign($scenario['reviewer'], rpSubmission($scenario['event'], $scenario['base']), ReviewAssignmentStatus::DeclinedConflict);
    $pending = rpAssign($scenario['reviewer'], rpSubmission($scenario['event'], $scenario['base']));

    rpList($scenario['organization'], $scenario['reviewer'])
        ->assertCanSeeTableRecords([$conflict, $pending])
        ->assertCanNotSeeTableRecords([$cancelled])
        ->assertTableActionHidden('review', $conflict)
        ->assertTableActionVisible('review', $pending);

    // La de conflicto se abre para consultarla, pero sin formulario de calificación.
    rpEvaluate($scenario['organization'], $scenario['reviewer'], $conflict)
        ->assertSee(__('reviewer.evaluate.cannot.conflict'))
        ->assertDontSee(__('reviewer.evaluate.submit'));
});

it('CA-011-13: the evaluation form saves a draft and then submits the review through SubmitReview', function (): void {
    $scenario = rpScenario();
    $assignment = rpAssign($scenario['reviewer'], $scenario['base']);
    $field = 'answers.'.$scenario['criterion']->id;

    $page = rpEvaluate($scenario['organization'], $scenario['reviewer'], $assignment)
        ->assertSee(__('reviewer.evaluate.submit'))
        ->assertSee('Relevancia');

    $page->fillForm([$field => 3])->call('saveDraft')->assertHasNoFormErrors();

    CurrentOrganization::run($scenario['organization'], function () use ($assignment): void {
        expect(ReviewAssignment::query()->findOrFail($assignment->id)->status)->toBe(ReviewAssignmentStatus::InProgress)
            ->and(Review::query()->where('review_assignment_id', $assignment->id)->sole()->revision)->toBe(0);
    });

    // El borrador se recarga en el formulario.
    rpEvaluate($scenario['organization'], $scenario['reviewer'], $assignment->refresh())->assertFormSet([$field => 3])
        ->fillForm([$field => 5])->call('submitReview')->assertHasNoFormErrors();

    CurrentOrganization::run($scenario['organization'], function () use ($assignment): void {
        expect(ReviewAssignment::query()->findOrFail($assignment->id)->status)->toBe(ReviewAssignmentStatus::Submitted)
            ->and(Review::query()->where('review_assignment_id', $assignment->id)->sole()->revision)->toBe(1);
    });
});

it('CA-011-14: an incomplete or out-of-scale form shows the errors next to the field and saves nothing', function (): void {
    $scenario = rpScenario();
    $assignment = rpAssign($scenario['reviewer'], $scenario['base']);
    $field = 'answers.'.$scenario['criterion']->id;

    $page = rpEvaluate($scenario['organization'], $scenario['reviewer'], $assignment);

    $page->fillForm([$field => null])->call('submitReview')->assertHasFormErrors([$field]);

    CurrentOrganization::run($scenario['organization'], function () use ($assignment): void {
        expect(Review::query()->where('review_assignment_id', $assignment->id)->exists())->toBeFalse()
            ->and(ReviewAssignment::query()->findOrFail($assignment->id)->status)->toBe(ReviewAssignmentStatus::Pending);
    });
});

it('CA-011-26: submitting after the period closed shows the visible error and saves nothing', function (): void {
    $scenario = rpScenario();
    $assignment = rpAssign($scenario['reviewer'], $scenario['base']);
    $field = 'answers.'.$scenario['criterion']->id;
    $page = rpEvaluate($scenario['organization'], $scenario['reviewer'], $assignment);

    // El periodo se cierra con la pantalla ya abierta.
    $scenario['event']->forceFill(['review_period_closed_at' => now()])->save();

    $page->fillForm([$field => 4])->call('submitReview')->assertNotified(__('reviewer.period_closed_error').' (REVIEW_PERIOD_CLOSED)');

    CurrentOrganization::run($scenario['organization'], fn () => expect(Review::query()->count())->toBe(0));
});

it('CA-011-13: the evaluation page of a type without a published rubric explains it and offers no form', function (): void {
    $scenario = rpScenario();
    $assignment = rpAssign($scenario['reviewer'], $scenario['base']);
    CurrentOrganization::run($scenario['organization'], fn () => Rubric::query()->whereKey($scenario['rubric']->id)->update(['status' => 'draft']));

    rpEvaluate($scenario['organization'], $scenario['reviewer'], $assignment)
        ->assertSee(__('reviewer.evaluate.cannot.no_rubric'))
        ->assertDontSee(__('reviewer.evaluate.submit'));
});

it('CA-011-12: the resource policy denies a user without any reviewer assignment in the organization', function (): void {
    $scenario = rpScenario();
    $assignment = rpAssign($scenario['reviewer'], $scenario['base']);
    $member = Memberships::attach(User::factory()->create(), $scenario['organization'], OrganizationRole::Reviewer);
    TwoFactor::enable($member);

    CurrentOrganization::set($scenario['organization']);
    Auth::login($member->fresh());

    expect(Gate::allows('viewAnyAssignments', Review::class))->toBeFalse()
        ->and(Gate::allows('viewAssignment', [Review::class, $assignment]))->toBeFalse();

    Auth::login($scenario['reviewer']);

    expect(Gate::allows('viewAnyAssignments', Review::class))->toBeTrue()
        ->and(Gate::allows('viewAssignment', [Review::class, $assignment]))->toBeTrue()
        ->and(AssignedSubmissionResource::canCreate())->toBeFalse();
});

it('CA-011-12: the reviewer downloads only the files of an assigned submission, with a neutral name on screen', function (): void {
    $scenario = rpScenario();
    Storage::fake(config('filesystems.private_uploads_disk'));
    $assignment = rpAssign($scenario['reviewer'], $scenario['base']);
    $other = rpSubmission($scenario['event'], $scenario['base']);

    $fileOf = function (Submission $submission) use ($scenario): SubmissionFile {
        return CurrentOrganization::run($scenario['organization'], function () use ($submission): SubmissionFile {
            $file = SubmissionFile::factory()->attachedTo($submission->id)->create([
                'organization_id' => $submission->organization_id,
                'call_id' => $submission->call_for_submissions_id,
                'original_name' => 'juan-perez-presentacion.pdf',
            ]);
            Storage::disk(config('filesystems.private_uploads_disk'))->put($file->storage_path, "%PDF-1.4\n");

            return $file;
        });
    };
    $mine = $fileOf($scenario['base']);
    $notMine = $fileOf($other);

    Storage::disk(config('filesystems.private_uploads_disk'))->buildTemporaryUrlsUsing(
        fn (string $path): string => 'https://bucket.test/'.$path.'?signature=abc',
    );
    $slug = $scenario['organization']->slug;

    $this->actingAs($scenario['reviewer'])->get("/reviewer/{$slug}/submission-files/{$mine->id}")->assertRedirect("https://bucket.test/{$mine->storage_path}?signature=abc");
    expect($this->actingAs($scenario['reviewer'])->get("/reviewer/{$slug}/submission-files/{$notMine->id}")->status())->toBeIn([403, 404]);

    // El evaluador queda registrado en la auditoría de la descarga.
    expect(Activity::query()->where('event', 'submission_file_downloaded')->where('causer_id', $scenario['reviewer']->id)->count())->toBe(1);

    // Un conflicto declarado retira el acceso a los archivos.
    CurrentOrganization::run($scenario['organization'], fn () => ReviewAssignment::query()->whereKey($assignment->id)->update(['status' => ReviewAssignmentStatus::DeclinedConflict]));
    expect($this->actingAs($scenario['reviewer'])->get("/reviewer/{$slug}/submission-files/{$mine->id}")->status())->toBeIn([403, 404]);
});

it('CA-011-12: a reviewer without the second factor confirmed cannot download files', function (): void {
    $scenario = rpScenario();
    Storage::fake(config('filesystems.private_uploads_disk'));
    rpAssign($scenario['reviewer'], $scenario['base']);
    $file = CurrentOrganization::run($scenario['organization'], fn (): SubmissionFile => SubmissionFile::factory()->attachedTo($scenario['base']->id)->create([
        'organization_id' => $scenario['organization']->id,
        'call_id' => $scenario['base']->call_for_submissions_id,
    ]));
    $scenario['reviewer']->clearTwoFactor();

    expect($this->actingAs($scenario['reviewer']->fresh())->get("/reviewer/{$scenario['organization']->slug}/submission-files/{$file->id}")->status())->toBe(403);
});

it('CA-011-12: the detail shows the neutral file name and never the original one', function (): void {
    $scenario = rpScenario();
    Storage::fake(config('filesystems.private_uploads_disk'));
    $assignment = rpAssign($scenario['reviewer'], $scenario['base']);
    CurrentOrganization::run($scenario['organization'], function () use ($scenario): void {
        $scenario['base']->forceFill([
            'form_snapshot' => ['fields' => [['key' => 'presentation', 'type' => 'file', 'label' => ['es' => 'Presentación']]]],
        ])->save();
        SubmissionFile::factory()->attachedTo($scenario['base']->id)->create([
            'organization_id' => $scenario['organization']->id,
            'call_id' => $scenario['base']->call_for_submissions_id,
            'original_name' => 'juan-perez-presentacion.pdf',
        ]);
    });

    rpEvaluate($scenario['organization'], $scenario['reviewer'], $assignment)
        ->assertSee('archivo-1.pdf')
        ->assertDontSee('juan-perez-presentacion.pdf');
});
