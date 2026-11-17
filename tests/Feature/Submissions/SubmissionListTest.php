<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\SubmissionStatus;
use App\Filament\Resources\Events\Pages\ManageEventSubmissions;
use App\Filament\Resources\Submissions\Tables\SubmissionsTable;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\Track;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/form-helpers.php';

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/**
 * Monta la lista de propuestas como organizador. Desde el plan 020 (F2) la lista es la página
 * `ManageEventSubmissions` del evento, que recibe el evento como `record`; el enrutamiento real
 * lo cubre `SubmissionPanelTest` por HTTP.
 *
 * @return Testable<ManageEventSubmissions>
 */
function submissionList(Organization $organization, User $user, Event $event): Testable
{
    Auth::login($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    return Livewire::test(ManageEventSubmissions::class, ['record' => $event->id]);
}

/**
 * Otra propuesta del mismo evento que reutiliza la convocatoria, el tipo, el formulario y el aviso de `$base`
 * (la factory crea uno nuevo de cada uno por propuesta y el aviso es único por evento y versión).
 *
 * @param  array<string, mixed>  $overrides
 */
function siblingSubmission(Submission $base, array $overrides = []): Submission
{
    return Submission::factory()->create([
        'organization_id' => $base->organization_id,
        'event_id' => $base->event_id,
        'call_for_submissions_id' => $base->call_for_submissions_id,
        'submission_type_id' => $base->submission_type_id,
        'form_definition_id' => $base->form_definition_id,
        'track_id' => $base->track_id,
        'privacy_notice_version_id' => $base->privacy_notice_version_id,
        ...$overrides,
    ]);
}

it('CA-009-2: combined filters return only the submissions that meet all of them', function (): void {
    [$organization, $event, $user] = formScenario(OrganizationRole::Organizer);

    $match = Submission::factory()->create([
        'organization_id' => $organization->id, 'event_id' => $event->id,
        'status' => SubmissionStatus::Submitted, 'submitted_at' => '2027-01-10 15:00:00',
    ]);
    $otherTrack = Track::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id]);
    $otherStatus = siblingSubmission($match, ['status' => SubmissionStatus::Withdrawn, 'submitted_at' => '2027-01-10 15:00:00', 'withdrawn_at' => '2027-01-11 10:00:00']);
    $otherDate = siblingSubmission($match, ['submitted_at' => '2027-02-20 15:00:00']);
    $otherTrackSubmission = siblingSubmission($match, ['track_id' => $otherTrack->id, 'submitted_at' => '2027-01-12 15:00:00']);

    submissionList($organization, $user, $event)
        ->assertCanSeeTableRecords([$match, $otherStatus, $otherDate, $otherTrackSubmission])
        ->filterTable('status', 'submitted')
        ->assertCanSeeTableRecords([$match, $otherDate, $otherTrackSubmission])
        ->assertCanNotSeeTableRecords([$otherStatus])
        ->filterTable('submitted_range', ['from' => '2027-01-01', 'until' => '2027-01-31'])
        ->assertCanSeeTableRecords([$match, $otherTrackSubmission])
        ->assertCanNotSeeTableRecords([$otherStatus, $otherDate])
        ->filterTable('track_id', $match->track_id)
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$otherStatus, $otherDate, $otherTrackSubmission])
        ->resetTableFilters()
        ->filterTable('call_for_submissions_id', $match->call_for_submissions_id)
        ->filterTable('submission_type_id', $match->submission_type_id)
        ->assertCanSeeTableRecords([$match, $otherStatus, $otherDate, $otherTrackSubmission]);
});

it('CA-009-3: the page size never goes above 100', function (): void {
    [$organization, $event, $user] = formScenario(OrganizationRole::Organizer);
    Submission::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id]);

    $list = submissionList($organization, $user, $event)->set('tableRecordsPerPage', 500);

    $page = $list->instance();
    assert($page instanceof ManageEventSubmissions);

    expect($page->getTableRecordsPerPage())->toBeLessThanOrEqual(SubmissionsTable::MAX_PER_PAGE)
        ->and(max($page->getTable()->getPaginationPageOptions()))->toBe(SubmissionsTable::MAX_PER_PAGE);
});

it('CA-009-4: a track of another event, of another organization or nonexistent gives the same validation error', function (): void {
    [$organization, $event, $user] = formScenario(OrganizationRole::Organizer);
    [$otherOrganization, $otherEvent] = formScenario();
    $sameOrgOtherEvent = Event::factory()->create(['organization_id' => $organization->id]);
    $trackOfOtherEvent = Track::factory()->create(['organization_id' => $organization->id, 'event_id' => $sameOrgOtherEvent->id]);
    $trackOfOtherOrganization = Track::factory()->create(['organization_id' => $otherOrganization->id, 'event_id' => $otherEvent->id]);

    $messages = [];

    foreach ([$trackOfOtherEvent->id, $trackOfOtherOrganization->id, (string) Str::uuid()] as $trackId) {
        $list = submissionList($organization, $user, $event)
            ->filterTable('track_id', $trackId)
            ->assertHasErrors(['tableFilters']);

        $messages[] = $list->errors()->get('tableFilters');
    }

    expect($messages[0])->not->toBeEmpty()
        ->and($messages[1])->toBe($messages[0])
        ->and($messages[2])->toBe($messages[0]);
});

it('CA-009-2: invited speakers (plan 018) never appear in the proposals list', function (): void {
    [$organization, $event, $user] = formScenario(OrganizationRole::Organizer);
    $call = Submission::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id]);
    $invited = Submission::factory()->invited()->create([
        'organization_id' => $organization->id,
        'event_id' => $event->id,
        'submission_type_id' => $call->submission_type_id,
        'form_definition_id' => $call->form_definition_id,
        'track_id' => $call->track_id,
    ]);

    submissionList($organization, $user, $event)
        ->assertCanSeeTableRecords([$call])
        ->assertCanNotSeeTableRecords([$invited]);
});
