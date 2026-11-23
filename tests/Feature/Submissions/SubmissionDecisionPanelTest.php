<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\SubmissionStatus;
use App\Filament\Resources\Submissions\Pages\ViewSubmission;
use App\Filament\Resources\Submissions\SubmissionResource;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/form-helpers.php';

/*
 * Plan 012, F1: las acciones de decisión en el detalle de la propuesta. Cada prueba usa un solo rol: la
 * caché de permisos de Spatie se comparte dentro del proceso.
 */

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

function decisionDetailUrl(Organization $organization, Event $event, Submission $submission): string
{
    return SubmissionResource::getUrl('view', ['event' => $event, 'record' => $submission], panel: 'admin', tenant: $organization);
}

function decisionSubmission(Organization $organization, Event $event, SubmissionStatus $status): Submission
{
    return Submission::factory()->create([
        'organization_id' => $organization->id,
        'event_id' => $event->id,
        'status' => $status,
    ]);
}

/** @return Testable<ViewSubmission> */
function decisionPage(Organization $organization, User $user, Event $event, Submission $submission): Testable
{
    Auth::login($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    return Livewire::test(ViewSubmission::class, ['record' => $submission->id, 'parentRecord' => $event]);
}

it('CA-012-7: opening the detail with GET never changes the state', function (): void {
    [$organization, $event, $admin] = formScenario();
    $submission = decisionSubmission($organization, $event, SubmissionStatus::UnderReview);

    $this->actingAs($admin)->get(decisionDetailUrl($organization, $event, $submission))->assertOk();
    $this->actingAs($admin)->get(decisionDetailUrl($organization, $event, $submission).'?action=accept')->assertOk();

    expect(CurrentOrganization::run($organization, fn () => Submission::query()->findOrFail($submission->id)->status))
        ->toBe(SubmissionStatus::UnderReview);
});

it('CA-012-5: an organizer of A gets 404 opening a submission of B by its identifier', function (): void {
    [$organizationA, $eventA, $organizerA] = formScenario(OrganizationRole::Organizer);
    [$organizationB, $eventB] = formScenario();
    $submissionB = decisionSubmission($organizationB, $eventB, SubmissionStatus::UnderReview);

    $this->actingAs($organizerA)->get(decisionDetailUrl($organizationA, $eventA, $submissionB))->assertNotFound();
    $this->actingAs($organizerA)->get(decisionDetailUrl($organizationA, $eventB, $submissionB))->assertNotFound();
});

it('CA-012-1: an organizer accepts from the detail with a comment and the history shows it', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    $submission = decisionSubmission($organization, $event, SubmissionStatus::UnderReview);

    decisionPage($organization, $organizer, $event, $submission)
        ->assertActionVisible('accept')
        ->assertActionVisible('waitlist')
        ->assertActionVisible('reject')
        ->assertActionHidden('revert')
        ->callAction('accept', ['author_comment' => 'Nos encanto'])
        ->assertHasNoActionErrors()
        ->assertSee('Nos encanto')
        ->assertActionHidden('accept')
        ->assertActionHidden('revert');

    expect(CurrentOrganization::run($organization, fn () => Submission::query()->findOrFail($submission->id)->status))
        ->toBe(SubmissionStatus::Accepted);
});

it('CA-012-2: a submitted proposal offers the direct decisions', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    $submission = decisionSubmission($organization, $event, SubmissionStatus::Submitted);

    decisionPage($organization, $organizer, $event, $submission)
        ->assertActionVisible('accept')
        ->assertActionVisible('waitlist')
        ->assertActionVisible('reject')
        ->callAction('waitlist')
        ->assertHasNoActionErrors()
        ->assertActionHidden('waitlist')
        ->assertActionVisible('accept')
        ->assertActionVisible('reject');

    expect(CurrentOrganization::run($organization, fn () => Submission::query()->findOrFail($submission->id)->status))
        ->toBe(SubmissionStatus::Waitlisted);
});

it('CA-012-3: only an admin sees and runs the revert action', function (): void {
    [$organization, $event, $admin] = formScenario();
    $submission = decisionSubmission($organization, $event, SubmissionStatus::Rejected);

    decisionPage($organization, $admin, $event, $submission)
        ->assertActionVisible('revert')
        ->assertActionHidden('accept')
        ->callAction('revert', ['reason' => 'Error de captura'])
        ->assertHasNoActionErrors()
        ->assertActionHidden('revert')
        ->assertActionVisible('accept');

    expect(CurrentOrganization::run($organization, fn () => Submission::query()->findOrFail($submission->id)->status))
        ->toBe(SubmissionStatus::UnderReview);
});

it('CA-012-3: an organizer does not get the revert action', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    $submission = decisionSubmission($organization, $event, SubmissionStatus::Accepted);

    decisionPage($organization, $organizer, $event, $submission)->assertActionHidden('revert');
});

it('CA-012-8: reverting a published decision from the screen requires the confirmation box', function (): void {
    [$organization, $event, $admin] = formScenario();
    $submission = decisionSubmission($organization, $event, SubmissionStatus::Accepted);
    CurrentOrganization::run($organization, fn () => Submission::query()->whereKey($submission->id)->update(['results_published_at' => now()]));

    decisionPage($organization, $admin, $event, $submission)
        ->callAction('revert', ['reason' => 'x'])
        ->assertHasActionErrors(['confirm_published']);

    expect(CurrentOrganization::run($organization, fn () => Submission::query()->findOrFail($submission->id)->status))
        ->toBe(SubmissionStatus::Accepted);

    decisionPage($organization, $admin, $event, $submission)
        ->callAction('revert', ['confirm_published' => true])
        ->assertHasNoActionErrors();

    expect(CurrentOrganization::run($organization, fn () => Submission::query()->findOrFail($submission->id)->status))
        ->toBe(SubmissionStatus::UnderReview);
});

it('CA-012-4: after another person decides, the screen only offers the actions valid for the new state', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    $submission = decisionSubmission($organization, $event, SubmissionStatus::UnderReview);

    $page = decisionPage($organization, $organizer, $event, $submission)->assertActionVisible('accept');

    // Otra persona rechazó la propuesta después de que se cargó esta pantalla.
    CurrentOrganization::run($organization, fn () => Submission::query()->whereKey($submission->id)->update(['status' => SubmissionStatus::Rejected]));

    $page->call('$refresh')
        ->assertActionHidden('accept')
        ->assertActionHidden('waitlist')
        ->assertActionHidden('reject');
});

it('CA-012-29: a comment over the maximum is refused by the form', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    $submission = decisionSubmission($organization, $event, SubmissionStatus::UnderReview);

    decisionPage($organization, $organizer, $event, $submission)
        ->callAction('reject', ['author_comment' => str_repeat('a', 1001)])
        ->assertHasActionErrors(['author_comment']);

    expect(CurrentOrganization::run($organization, fn () => Submission::query()->findOrFail($submission->id)->status))
        ->toBe(SubmissionStatus::UnderReview);
});

it('CA-012-28: the history prints the author comment escaped', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    $submission = decisionSubmission($organization, $event, SubmissionStatus::UnderReview);

    decisionPage($organization, $organizer, $event, $submission)
        ->callAction('reject', ['author_comment' => '<script>alert(1)</script>Gracias <b>mucho</b>'])
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertDontSeeHtml('<b>mucho</b>')
        ->assertSee('Gracias mucho');
});
