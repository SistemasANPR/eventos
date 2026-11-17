<?php

declare(strict_types=1);

use App\Actions\SubmissionExports\ExportSubmissions;
use App\Enums\OrganizationRole;
use App\Enums\SubmissionStatus;
use App\Filament\Resources\Submissions\Pages\ListSubmissions;
use App\Filament\Resources\Submissions\Tables\SubmissionsTable;
use App\Jobs\ExportSubmissionsJob;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\Track;
use App\Models\User;
use App\Support\SubmissionExport\RecentExports;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/form-helpers.php';

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/**
 * Lista de propuestas con el evento padre fijado desde la prueba: Livewire monta el componente en una
 * ruta de prueba sin el parámetro `{event}`. El enrutamiento real lo cubre `SubmissionPanelTest` por HTTP.
 */
class ListSubmissionsForEvent extends ListSubmissions
{
    public static ?Event $event = null;

    public function mountParentRecord(): void
    {
        $this->parentRecord = self::$event;
    }
}

/**
 * Monta la lista de propuestas como organizador.
 *
 * @return Testable<ListSubmissionsForEvent>
 */
function submissionList(Organization $organization, User $user, Event $event): Testable
{
    Auth::login($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);
    ListSubmissionsForEvent::$event = $event;

    return Livewire::test(ListSubmissionsForEvent::class);
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
    assert($page instanceof ListSubmissions);

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

it('CA-009-11: the export action exports only the rows of the active filters and tells the user it is in progress', function (): void {
    Storage::fake(config('filesystems.private_uploads_disk'));
    [$organization, $event, $user] = formScenario(OrganizationRole::Organizer);
    $submitted = Submission::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id, 'title' => 'Enviada visible']);
    siblingSubmission($submitted, ['title' => 'Retirada filtrada', 'status' => SubmissionStatus::Withdrawn, 'withdrawn_at' => '2027-01-11 10:00:00']);

    submissionList($organization, $user, $event)
        ->filterTable('status', 'submitted')
        ->callAction('export', ['format' => 'csv'])
        ->assertNotified(__('submission_panel.export.queued_title'));

    $entry = Activity::query()->where('event', 'submissions_exported')->sole();
    $file = "organizations/{$organization->id}/exports/{$entry->properties['export_id']}.csv";
    $content = Storage::disk(config('filesystems.private_uploads_disk'))->get($file);

    expect($entry->properties['rows'])->toBe(1)
        ->and($entry->properties['filters']['status'])->toBe('submitted')
        ->and($content)->toContain('Enviada visible')
        ->and($content)->not->toContain('Retirada filtrada');
});

it('CA-009-15: the export action is hidden for a member without submissions.export', function (): void {
    [$organization, $event] = formScenario(OrganizationRole::Organizer);
    $viewer = Memberships::attach(User::factory()->create(), $organization);
    CurrentOrganization::run($organization, fn () => $viewer->givePermissionTo(['events.manage', 'submissions.view']));

    submissionList($organization, $viewer, $event)
        ->assertActionHidden('export')
        ->assertActionHidden('recentExports');
});

it('CA-009-16: the export action shows the too-large message and queues nothing', function (): void {
    Storage::fake(config('filesystems.private_uploads_disk'));
    Queue::fake();
    [$organization, $event, $user] = formScenario(OrganizationRole::Organizer);
    $base = Submission::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id]);
    siblingSubmission($base);

    // Con un tope de una fila, las dos propuestas del evento lo superan.
    app()->bind(ExportSubmissions::class, fn (): ExportSubmissions => new ExportSubmissions(maxRows: 1));

    submissionList($organization, $user, $event)
        ->callAction('export', ['format' => 'csv'])
        ->assertNotified();

    // Crear propuestas encola otras cosas (avisos): solo importa que NO se encole la exportación.
    Queue::assertNotPushed(ExportSubmissionsJob::class);
    expect(Activity::query()->where('event', 'like', 'submissions_export%')->count())->toBe(0);
});

it('CA-009-19: the recent exports list shows only my exports with a signed download link', function (): void {
    Storage::fake(config('filesystems.private_uploads_disk'));
    [$organization, $event, $user] = formScenario(OrganizationRole::Organizer);
    $other = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer);
    Submission::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id]);

    Auth::login($user);
    CurrentOrganization::set($organization);
    app(ExportSubmissions::class)->handle($event, [], $user, 'csv');
    Auth::login($other);
    app(ExportSubmissions::class)->handle($event, [], $other, 'csv');

    $mine = RecentExports::for($user, $organization);

    expect($mine)->toHaveCount(1)
        ->and($mine[0]['rows'])->toBe(1)
        ->and($mine[0]['url'])->toContain('signature=')
        ->and($mine[0]['url'])->toContain("user={$user->id}");
});
