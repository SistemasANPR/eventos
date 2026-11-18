<?php

declare(strict_types=1);

use App\Actions\Program\CreateSpeaker;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\ManageEventSpeakers;
use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Organization;
use App\Models\Speaker;
use App\Models\SpeakerCategory;
use App\Models\User;
use App\Support\Files\MediaDisk;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/form-helpers.php';

/*
 * Plan 021, F2: «Ponentes» como sección del evento. Cada prueba usa un solo rol: la caché de permisos
 * de Spatie se comparte dentro del proceso. Corren con APP_ENV=testing (ver el plan, F1).
 */

beforeEach(function (): void {
    Storage::fake(MediaDisk::name());
});

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/** @return Testable<ManageEventSpeakers> */
function speakersPage(Organization $organization, User $user, Event $event): Testable
{
    Auth::login($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    return Livewire::test(ManageEventSpeakers::class, ['record' => $event->id]);
}

/** @param  array<string, mixed>  $data */
function panelSpeaker(User $actor, Event $event, array $data = []): Speaker
{
    return app(CreateSpeaker::class)->handle($actor, $event, ['first_name' => 'Ada', 'last_name' => 'Lovelace', ...$data]);
}

it('CA-021-1: the empty list shows its empty state and the admin creates a speaker with only first and last name', function (): void {
    [$organization, $event, $admin] = formScenario();

    $component = speakersPage($organization, $admin, $event)
        ->assertSee(__('program.speakers.empty_heading'));

    $component->callAction('createSpeaker', ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'is_published' => true])
        ->assertHasNoActionErrors();

    $speaker = Speaker::query()->where('event_id', $event->id)->firstOrFail();

    $component->assertCanSeeTableRecords([$speaker]);

    expect($speaker->full_name)->toBe('Ada Lovelace')
        ->and($speaker->is_published)->toBeTrue();
});

it('CA-021-1: a missing last name shows an error next to the field and creates nothing', function (): void {
    [$organization, $event, $admin] = formScenario();

    speakersPage($organization, $admin, $event)
        ->callAction('createSpeaker', ['first_name' => 'Ada', 'last_name' => ''])
        ->assertHasActionErrors(['last_name' => 'required']);

    expect(Speaker::query()->count())->toBe(0);
});

it('CA-021-1: a repeated email is shown as a field error', function (): void {
    [$organization, $event, $admin] = formScenario();
    panelSpeaker($admin, $event, ['email' => 'ada@example.com']);

    speakersPage($organization, $admin, $event)
        ->callAction('createSpeaker', ['first_name' => 'Otra', 'last_name' => 'Persona', 'email' => 'ADA@example.com'])
        ->assertHasActionErrors(['email']);

    expect(Speaker::query()->count())->toBe(1);
});

it('CA-021-2: editing saves the one-line description, networks and a sanitized biography', function (): void {
    [$organization, $event, $admin] = formScenario();
    $speaker = panelSpeaker($admin, $event);

    speakersPage($organization, $admin, $event)
        ->callAction(TestAction::make('editSpeaker')->table($speaker), [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'company' => 'Analytical Engines',
            'tagline' => 'Escribió el primer programa',
            'bio' => '<p onclick="alert(1)">Hola</p><script>alert(2)</script>',
            'social_links' => [['network' => 'linkedin', 'url' => 'https://www.linkedin.com/in/ada']],
            'is_published' => true,
        ])
        ->assertHasNoActionErrors();

    $fresh = Speaker::query()->findOrFail($speaker->id);

    expect($fresh->company)->toBe('Analytical Engines')
        ->and($fresh->tagline)->toBe('Escribió el primer programa')
        ->and($fresh->social_links)->toHaveCount(1)
        ->and((string) $fresh->bio)->toContain('Hola')
        ->and((string) $fresh->bio)->not->toContain('script', 'onclick');
});

it('CA-021-2: a network with an address of another domain is reported next to the networks and not saved', function (): void {
    [$organization, $event, $admin] = formScenario();
    $speaker = panelSpeaker($admin, $event);

    speakersPage($organization, $admin, $event)
        ->callAction(TestAction::make('editSpeaker')->table($speaker), [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'social_links' => [['network' => 'linkedin', 'url' => 'https://evil.test/ada']],
            'is_published' => true,
        ])
        ->assertHasActionErrors(['social_links']);

    expect(Speaker::query()->findOrFail($speaker->id)->social_links)->toBe([]);
});

it('CA-021-3: a photo uploaded from the form is stored on the media disk and a fake one is rejected', function (): void {
    [$organization, $event, $admin] = formScenario();
    $speaker = panelSpeaker($admin, $event);
    $component = speakersPage($organization, $admin, $event);

    $component->callAction(TestAction::make('editSpeaker')->table($speaker), [
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'photo' => UploadedFile::fake()->createWithContent('a.png', 'esto no es una imagen'),
        'is_published' => true,
    ]);

    expect(Speaker::query()->findOrFail($speaker->id)->photo_path)->toBeNull()
        ->and(Storage::disk(MediaDisk::name())->allFiles())->toBe([]);

    // El error deja el modal abierto: una página nueva para el segundo intento.
    speakersPage($organization, $admin, $event)->callAction(TestAction::make('editSpeaker')->table($speaker), [
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'photo' => UploadedFile::fake()->image('retrato.png', 30, 30),
        'is_published' => true,
    ])->assertHasNoActionErrors();

    $path = (string) Speaker::query()->findOrFail($speaker->id)->photo_path;

    expect($path)->toStartWith("organizations/{$organization->id}/events/{$event->id}/media/photo/")
        ->and(Storage::disk(MediaDisk::name())->exists($path))->toBeTrue();
});

it('CA-021-18: the list shows a thumbnail of the photo without publishing it', function (): void {
    [$organization, $event, $admin] = formScenario();
    $speaker = panelSpeaker($admin, $event, ['photo' => UploadedFile::fake()->image('a.png', 30, 30)]);

    speakersPage($organization, $admin, $event)
        ->assertCanSeeTableRecords([$speaker])
        ->assertSeeHtml('data:image/jpeg;base64,');

    expect(Storage::disk(MediaDisk::name())->getVisibility((string) $speaker->photo_path))->toBe('private');
});

it('CA-021-1: the list shows names, company, job title, categories and the publication state with the reversal badge', function (): void {
    [$organization, $event, $admin] = formScenario();
    $category = SpeakerCategory::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id, 'name' => 'Magistral']);
    $visible = panelSpeaker($admin, $event, ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'company' => 'Analytical', 'job_title' => 'Matemática', 'category_ids' => [$category->id]]);
    $hidden = panelSpeaker($admin, $event, ['first_name' => 'Grace', 'last_name' => 'Hopper', 'is_published' => false]);
    DB::table('speakers')->where('id', $hidden->id)->update(['hidden_by_reversal_at' => now()]);
    $manual = panelSpeaker($admin, $event, ['first_name' => 'Alan', 'last_name' => 'Turing', 'is_published' => false]);

    speakersPage($organization, $admin, $event)
        ->assertCanSeeTableRecords([$visible, $hidden, $manual])
        ->assertSee(['Ada', 'Lovelace', 'Analytical', 'Matemática', 'Magistral'])
        ->assertSee(__('program.speakers.hidden_by_reversal'))
        ->assertSee(__('program.speakers.filters.published_no'));
});

it('CA-021-1: filters by category and by publication, and searches by name', function (): void {
    [$organization, $event, $admin] = formScenario();
    $category = SpeakerCategory::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id, 'name' => 'Magistral']);
    $ada = panelSpeaker($admin, $event, ['first_name' => 'Ada', 'category_ids' => [$category->id]]);
    $grace = panelSpeaker($admin, $event, ['first_name' => 'Grace', 'last_name' => 'Hopper', 'is_published' => false]);

    speakersPage($organization, $admin, $event)
        ->filterTable('category', $category->id)
        ->assertCanSeeTableRecords([$ada])
        ->assertCanNotSeeTableRecords([$grace])
        ->resetTableFilters()
        ->filterTable('is_published', false)
        ->assertCanSeeTableRecords([$grace])
        ->assertCanNotSeeTableRecords([$ada])
        ->resetTableFilters()
        ->searchTable('Hopper')
        ->assertCanSeeTableRecords([$grace])
        ->assertCanNotSeeTableRecords([$ada]);
});

it('CA-021-1: the page paginates by 25, 50 or 100', function (): void {
    [$organization, $event, $admin] = formScenario();
    $table = speakersPage($organization, $admin, $event)->instance()->getTable();

    expect($table->getPaginationPageOptions())->toBe([25, 50, 100])
        ->and($table->getDefaultPaginationPageOption())->toBe(25);
});

it('CA-021-1: the row action publishes and unpublishes', function (): void {
    [$organization, $event, $admin] = formScenario();
    $speaker = panelSpeaker($admin, $event);
    $component = speakersPage($organization, $admin, $event);

    $component->callAction(TestAction::make('togglePublication')->table($speaker));

    expect(Speaker::query()->findOrFail($speaker->id)->is_published)->toBeFalse();

    $component->callAction(TestAction::make('togglePublication')->table($speaker));

    expect(Speaker::query()->findOrFail($speaker->id)->is_published)->toBeTrue();
});

it('CA-021-16: the admin deletes a speaker and it leaves the list', function (): void {
    [$organization, $event, $admin] = formScenario();
    $speaker = panelSpeaker($admin, $event);
    $component = speakersPage($organization, $admin, $event);

    $component->callAction(TestAction::make('deleteSpeaker')->table($speaker));

    expect(Speaker::query()->count())->toBe(0)
        ->and(Speaker::query()->withTrashed()->count())->toBe(1);

    $component->assertCanNotSeeTableRecords([$speaker]);
});

it('CA-021-15: an organizer creates and edits but is not offered the delete action', function (): void {
    [$organization, $event, $organizer, $admin] = formScenario(OrganizationRole::Organizer);
    $speaker = panelSpeaker($admin, $event);

    speakersPage($organization, $organizer, $event)
        ->assertActionVisible('createSpeaker')
        ->assertActionVisible(TestAction::make('editSpeaker')->table($speaker))
        ->assertActionVisible(TestAction::make('togglePublication')->table($speaker))
        ->assertActionHidden(TestAction::make('deleteSpeaker')->table($speaker));
});

it('CA-021-15: a reviewer has no access to the page, by class or by URL', function (): void {
    [$organization, $event, $reviewer] = formScenario(OrganizationRole::Reviewer);
    Auth::login($reviewer);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    expect(ManageEventSpeakers::canAccess(['record' => $event]))->toBeFalse()
        ->and($this->actingAs($reviewer)->get(EventResource::getUrl('speakers', ['record' => $event], panel: 'admin', tenant: $organization))->status())->toBeIn([403, 404]);
});

it('CA-021-15: the admin reaches the page by URL and the event sub-navigation links to it', function (): void {
    [$organization, $event, $admin] = formScenario();
    $url = EventResource::getUrl('speakers', ['record' => $event], panel: 'admin', tenant: $organization);

    $this->actingAs($admin)->get($url)->assertOk()->assertSee($url, false);
    $this->actingAs($admin)->get(EventResource::getUrl('view', ['record' => $event], panel: 'admin', tenant: $organization))
        ->assertOk()
        ->assertSee(__('program.speakers.navigation'));
});

it('CA-021-17: the speakers of an event of another organization answer 404', function (): void {
    [$organizationA, , $adminA] = formScenario();
    [, $eventB] = formScenario();

    // Control: la prueba anterior abre la misma ruta con un evento propio y responde 200; este 404 es por el aislamiento.
    $this->actingAs($adminA)->get(EventResource::getUrl('speakers', ['record' => $eventB], panel: 'admin', tenant: $organizationA))->assertNotFound();
});

it('CA-021-17: the list never shows the speakers of another event or of another organization', function (): void {
    [$organizationA, $eventA, $adminA] = formScenario();
    [, $eventB, $adminB] = formScenario();
    $otherEvent = Event::factory()->create(['organization_id' => $organizationA->id]);
    $own = panelSpeaker($adminA, $eventA);
    $ofOtherEvent = panelSpeaker($adminA, $otherEvent, ['first_name' => 'Otro']);
    $foreign = panelSpeaker($adminB, $eventB, ['first_name' => 'Ajeno']);

    speakersPage($organizationA, $adminA, $eventA)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$ofOtherEvent])
        ->assertCanNotSeeTableRecords([Speaker::query()->withoutGlobalScopes()->findOrFail($foreign->id)])
        ->assertDontSee('Ajeno');
});

/** @return list<EventActivity> actividades nuevas del evento */
function panelSpeakerActivities(Event $event, int $count = 2): array
{
    return CurrentOrganization::run($event->organization, fn (): array => EventActivity::factory()->count($count)->create(['event_id' => $event->id])->all());
}

it('CA-021-5: the speaker form assigns activities of the event when creating and replaces them when editing', function (): void {
    [$organization, $event, $admin] = formScenario();
    [$first, $second] = panelSpeakerActivities($event);

    speakersPage($organization, $admin, $event)
        ->callAction('createSpeaker', ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'activity_ids' => [$first->id, $second->id]])
        ->assertHasNoActionErrors();

    $speaker = Speaker::query()->where('event_id', $event->id)->firstOrFail();
    $ids = fn (): array => DB::table('activity_speaker')->where('speaker_id', $speaker->id)->orderBy('activity_id')->pluck('activity_id')->all();

    expect($ids())->toEqualCanonicalizing([$first->id, $second->id]);

    speakersPage($organization, $admin, $event)
        ->callAction(TestAction::make('editSpeaker')->table($speaker), ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'activity_ids' => [$second->id]])
        ->assertHasNoActionErrors();

    expect($ids())->toBe([$second->id]);
});

it('CA-021-5 CA-021-17: the speaker form refuses an activity of another event or organization', function (): void {
    [$organization, $event, $admin] = formScenario();
    [, $otherEvent] = formScenario();
    [$foreign] = panelSpeakerActivities($otherEvent, 1);

    speakersPage($organization, $admin, $event)
        ->callAction('createSpeaker', ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'activity_ids' => [$foreign->id]])
        ->assertHasActionErrors();

    expect(Speaker::query()->count())->toBe(0)->and(DB::table('activity_speaker')->count())->toBe(0);
});
