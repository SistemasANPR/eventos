<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\SubmissionStatus;
use App\Filament\Resources\Events\Pages\ManageEventActivities;
use App\Filament\Resources\Events\Pages\ManageEventSubmissions;
use App\Filament\Resources\Submissions\SubmissionResource;
use App\Filament\Resources\Submissions\Support\SubmissionActivityLink;
use App\Models\Event;
use App\Models\EventActivity;
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
 * Plan 021, F6: la vista Propuestas sin las aceptadas por defecto y el enlace entre propuesta y actividad.
 */

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/** @return Testable<ManageEventSubmissions> */
function proposalsPage(Organization $organization, User $user, Event $event): Testable
{
    Auth::login($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    return Livewire::test(ManageEventSubmissions::class, ['record' => $event->id]);
}

/**
 * Una propuesta del evento; las siguientes reutilizan convocatoria, tipo, formulario y aviso de la primera
 * (el aviso de privacidad es único por evento y versión).
 *
 * @param  array<string, mixed>  $attributes
 */
function proposalOf(Organization $organization, Event $event, SubmissionStatus $status, array $attributes = []): Submission
{
    return CurrentOrganization::run($organization, function () use ($organization, $event, $status, $attributes): Submission {
        $base = Submission::query()->where('event_id', $event->id)->first();
        $shared = $base === null ? [] : [
            'call_for_submissions_id' => $base->call_for_submissions_id,
            'submission_type_id' => $base->submission_type_id,
            'form_definition_id' => $base->form_definition_id,
            'track_id' => $base->track_id,
            'privacy_notice_version_id' => $base->privacy_notice_version_id,
        ];

        return Submission::factory()->create([
            'organization_id' => $organization->id,
            'event_id' => $event->id,
            'status' => $status,
            ...$shared,
            ...$attributes,
        ]);
    });
}

function activityOfProposal(Organization $organization, Event $event, ?Submission $submission, string $title): EventActivity
{
    return CurrentOrganization::run($organization, fn (): EventActivity => EventActivity::factory()->unscheduled()->create([
        'event_id' => $event->id,
        'submission_id' => $submission?->id,
        'title' => $title,
    ]));
}

function proposalViewUrl(Organization $organization, Event $event, Submission $submission): string
{
    return SubmissionResource::getUrl('view', ['event' => $event, 'record' => $submission], panel: 'admin', tenant: $organization);
}

it('CA-021-14: the proposals list hides the accepted ones by default', function (): void {
    [$organization, $event, $admin] = formScenario();
    $submitted = proposalOf($organization, $event, SubmissionStatus::Submitted);
    $accepted = proposalOf($organization, $event, SubmissionStatus::Accepted);

    proposalsPage($organization, $admin, $event)
        ->assertCanSeeTableRecords([$submitted])
        ->assertCanNotSeeTableRecords([$accepted]);
});

it('CA-021-14: the status filter shows the accepted proposals, and the toggle shows all of them', function (): void {
    [$organization, $event, $admin] = formScenario();
    $submitted = proposalOf($organization, $event, SubmissionStatus::Submitted);
    $accepted = proposalOf($organization, $event, SubmissionStatus::Accepted);

    proposalsPage($organization, $admin, $event)
        ->filterTable('status', 'accepted')
        ->assertCanSeeTableRecords([$accepted])
        ->assertCanNotSeeTableRecords([$submitted])
        ->resetTableFilters()
        ->filterTable('show_accepted', true)
        ->assertCanSeeTableRecords([$submitted, $accepted]);
});

it('CA-021-14: a proposal links to its activity when the viewer can see activities', function (): void {
    [$organization, $event, $admin] = formScenario();
    $accepted = proposalOf($organization, $event, SubmissionStatus::Accepted);
    activityOfProposal($organization, $event, $accepted, 'Charla enlazada');

    proposalsPage($organization, $admin, $event)
        ->filterTable('status', 'accepted')
        ->assertSee('Charla enlazada');
});

it('CA-021-14: the proposal detail links to its activity, and the activity links back to the proposal', function (): void {
    [$organization, $event, $admin] = formScenario();
    $accepted = proposalOf($organization, $event, SubmissionStatus::Accepted, ['title' => 'Propuesta madre']);
    activityOfProposal($organization, $event, $accepted, 'Charla enlazada');

    $this->actingAs($admin)->get(proposalViewUrl($organization, $event, $accepted))
        ->assertOk()
        ->assertSee('Charla enlazada');

    Auth::login($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    Livewire::test(ManageEventActivities::class, ['record' => $event->id])
        ->assertSee(proposalViewUrl($organization, $event, $accepted), false);
});

it('CA-021-14: who cannot see activities gets no link from the proposal to the activity', function (): void {
    [$organization, $event, $reviewer] = formScenario(OrganizationRole::Reviewer);
    $accepted = proposalOf($organization, $event, SubmissionStatus::Accepted);
    activityOfProposal($organization, $event, $accepted, 'Charla enlazada');

    // Ningún rol ve propuestas sin poder ver actividades; el enlace decide por su propia Policy.
    Auth::login($reviewer);
    CurrentOrganization::set($organization);

    expect(SubmissionActivityLink::for($accepted->fresh() ?? $accepted))->toBeNull();

    $this->actingAs($reviewer)->get(proposalViewUrl($organization, $event, $accepted))->assertForbidden();
});

it('CA-021-14: a proposal never shows the activity of another organization', function (): void {
    [$organization, $event, $admin] = formScenario();
    [$otherOrganization, $otherEvent] = formScenario();
    $accepted = proposalOf($organization, $event, SubmissionStatus::Accepted);
    activityOfProposal($otherOrganization, $otherEvent, null, 'Charla ajena');

    $this->actingAs($admin)->get(proposalViewUrl($organization, $event, $accepted))
        ->assertOk()
        ->assertDontSee('Charla ajena');

    expect(CurrentOrganization::run($organization, fn () => $accepted->fresh()?->activity))->toBeNull();
});
