<?php

declare(strict_types=1);

use App\Actions\Calls\ArchiveTrack;
use App\Actions\Calls\CreateTrack;
use App\Actions\Calls\DeleteTrack;
use App\Actions\Calls\ReorderTracks;
use App\Actions\Calls\RestoreTrack;
use App\Actions\Calls\UpdateTrack;
use App\Actions\Events\CreateEvent;
use App\Enums\OrganizationRole;
use App\Exceptions\Domain\TrackInUse;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Resources\Events\RelationManagers\TracksRelationManager;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Track;
use App\Models\User;
use App\Support\Calls\NoTrackUsage;
use App\Support\Calls\TrackUsageChecker;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Memberships;
use Tests\Support\TrackUsage;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/** @return array{0: Organization, 1: Event, 2: User} organización con roles, evento (es, en) y el usuario con el rol indicado */
function trackScenario(OrganizationRole $role = OrganizationRole::OrgAdmin): array
{
    $organization = Memberships::organization();
    $user = Memberships::attach(User::factory()->create(), $organization, $role);
    $admin = $role === OrganizationRole::OrgAdmin ? $user : Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $event = app(CreateEvent::class)->handle($admin, $organization, [
        'name' => ['es' => 'Congreso', 'en' => 'Congress'],
        'slug' => 'congreso-'.Str::lower(Str::random(6)),
        'type' => 'congress',
        'starts_at' => '2027-03-10 09:00',
        'ends_at' => '2027-03-12 18:00',
        'timezone' => 'America/Mexico_City',
        'locales' => ['es', 'en'],
        'default_locale' => 'es',
        'currency' => 'MXN',
    ]);

    return [$organization, $event, $user];
}

/** @return list<string> nombres en español, en el orden de la lista del evento */
function trackNames(Event $event): array
{
    $organization = Organization::query()->findOrFail($event->organization_id);

    return CurrentOrganization::run($organization, fn (): array => Track::query()
        ->where('event_id', $event->id)->orderBy('position')->get()
        ->map(fn (Track $track): string => $track->getTranslation('name', 'es'))->all());
}

/** @param  array<string, mixed>  $name */
function trackCreate(User $actor, Event $event, array $name): Track
{
    return app(CreateTrack::class)->handle($actor, $event, ['name' => $name]);
}

it('CA-004-12: an organizer creates tracks with name and description in es and en, and they are listed in creation order', function (): void {
    [$organization, $event, $admin] = trackScenario();

    $first = app(CreateTrack::class)->handle($admin, $event, [
        'name' => ['es' => 'Ciencia', 'en' => 'Science'],
        'description' => ['es' => 'Investigación básica', 'en' => 'Basic research'],
    ]);
    app(CreateTrack::class)->handle($admin, $event, ['name' => ['es' => 'Tecnología']]);
    CurrentOrganization::set($organization);

    expect(trackNames($event))->toBe(['Ciencia', 'Tecnología'])
        ->and($first->position)->toBe(1)
        ->and($first->fresh()->getTranslation('name', 'en'))->toBe('Science')
        ->and($first->fresh()->getTranslation('description', 'en'))->toBe('Basic research');
});

it('CA-004-12: tracks are scoped to their event: another event starts its own list', function (): void {
    [$organization, $event, $admin] = trackScenario();
    $otherEvent = Event::factory()->create(['organization_id' => $organization->id, 'locales' => ['es'], 'default_locale' => 'es']);

    trackCreate($admin, $event, ['es' => 'Ciencia']);
    $other = trackCreate($admin, $otherEvent, ['es' => 'Arte']);

    expect($other->position)->toBe(1)
        ->and(trackNames($event))->toBe(['Ciencia'])
        ->and(trackNames($otherEvent))->toBe(['Arte']);
});

it('CA-004-12: the organizer reorders the tracks and the new order is stored and listed', function (): void {
    [, $event, $admin] = trackScenario();
    $a = trackCreate($admin, $event, ['es' => 'A']);
    $b = trackCreate($admin, $event, ['es' => 'B']);
    $c = trackCreate($admin, $event, ['es' => 'C']);

    app(ReorderTracks::class)->handle($admin, $event, [$c->id, $a->id, $b->id]);

    expect(trackNames($event))->toBe(['C', 'A', 'B']);
});

it('CA-004-12: reordering requires exactly the tracks of the event, no more and no less', function (array $ids): void {
    [, $event, $admin] = trackScenario();
    trackCreate($admin, $event, ['es' => 'A']);
    $b = trackCreate($admin, $event, ['es' => 'B']);

    $ids = array_map(fn (string $id): string => $id === 'b' ? $b->id : $id, $ids);

    expect(fn () => app(ReorderTracks::class)->handle($admin, $event, $ids))->toThrow(ValidationException::class)
        ->and(trackNames($event))->toBe(['A', 'B']);
})->with([
    'a missing track' => [['b']],
    'a repeated track' => [['b', 'b']],
    'an unknown track' => [['b', '00000000-0000-7000-8000-000000000000']],
    'nothing' => [[]],
]);

it('CA-004-12: an organizer edits name and description by language', function (): void {
    [, $event, $admin] = trackScenario();
    $track = trackCreate($admin, $event, ['es' => 'Ciencia']);

    app(UpdateTrack::class)->handle($admin, $track, [
        'name' => ['es' => 'Ciencias', 'en' => 'Sciences'],
        'description' => ['es' => 'Todas las ciencias'],
    ]);
    $fresh = Track::query()->withoutGlobalScopes()->findOrFail($track->id);

    expect($fresh->getTranslation('name', 'es'))->toBe('Ciencias')
        ->and($fresh->getTranslation('name', 'en'))->toBe('Sciences')
        ->and($fresh->getTranslation('description', 'es'))->toBe('Todas las ciencias');
});

it('CA-004-13: a track without a name in the base language of the event is refused', function (array $name): void {
    [, $event, $admin] = trackScenario();
    $track = trackCreate($admin, $event, ['es' => 'Ciencia']);

    expect(fn () => app(CreateTrack::class)->handle($admin, $event, ['name' => $name]))->toThrow(ValidationException::class)
        ->and(fn () => app(UpdateTrack::class)->handle($admin, $track, ['name' => $name]))->toThrow(ValidationException::class)
        ->and(trackNames($event))->toBe(['Ciencia']);
})->with([
    'only another language' => [['en' => 'Science']],
    'blank base language' => [['es' => '   ', 'en' => 'Science']],
    'nothing' => [[]],
]);

it('CA-004-13: a language without a translation falls back to the base language when shown', function (): void {
    [$organization, $event, $admin] = trackScenario();
    $track = app(CreateTrack::class)->handle($admin, $event, [
        'name' => ['es' => 'Ciencia'],
        'description' => ['es' => 'Investigación'],
    ]);
    CurrentOrganization::set($organization);
    $fresh = $track->fresh();

    expect($fresh->label('en'))->toBe('Ciencia')
        ->and($fresh->descriptionFor('en'))->toBe('Investigación')
        ->and($fresh->label('es'))->toBe('Ciencia');
});

it('CA-004-13: a track without description shows none', function (): void {
    [$organization, $event, $admin] = trackScenario();
    $track = trackCreate($admin, $event, ['es' => 'Ciencia']);
    CurrentOrganization::set($organization);

    expect($track->fresh()->descriptionFor('es'))->toBeNull();
});

it('CA-004-14: a track in use cannot be deleted: TRACK_IN_USE, and it can only be archived', function (): void {
    [$organization, $event, $admin] = trackScenario();
    $track = trackCreate($admin, $event, ['es' => 'Ciencia']);
    TrackUsage::install($track);

    expect(fn () => app(DeleteTrack::class)->handle($admin, $track))->toThrow(TrackInUse::class)
        ->and(TrackInUse::CODE)->toBe('TRACK_IN_USE')
        ->and(trackNames($event))->toBe(['Ciencia']);

    app(ArchiveTrack::class)->handle($admin, $track);
    CurrentOrganization::set($organization);

    expect($track->fresh()->isArchived())->toBeTrue()
        ->and(trackNames($event))->toBe(['Ciencia']);
});

it('CA-004-14: a track that nobody uses can be deleted', function (): void {
    [, $event, $admin] = trackScenario();
    $track = trackCreate($admin, $event, ['es' => 'Ciencia']);

    expect(app(TrackUsageChecker::class))->toBeInstanceOf(NoTrackUsage::class);

    app(DeleteTrack::class)->handle($admin, $track);

    expect(trackNames($event))->toBe([]);
});

it('CA-004-14: an archived track is not offered to new submissions but stays in the event and can be restored', function (): void {
    [$organization, $event, $admin] = trackScenario();
    $keep = trackCreate($admin, $event, ['es' => 'Ciencia']);
    $track = trackCreate($admin, $event, ['es' => 'Arte']);

    app(ArchiveTrack::class)->handle($admin, $track);
    CurrentOrganization::set($organization);

    expect(Track::query()->active()->where('event_id', $event->id)->pluck('id')->all())->toBe([$keep->id])
        ->and(Track::query()->where('event_id', $event->id)->count())->toBe(2);

    app(RestoreTrack::class)->handle($admin, $track);

    expect(Track::query()->active()->where('event_id', $event->id)->count())->toBe(2);
});

it('CA-004-14: archiving and restoring are idempotent and leave no extra audit record', function (): void {
    [, $event, $admin] = trackScenario();
    $track = trackCreate($admin, $event, ['es' => 'Ciencia']);

    app(RestoreTrack::class)->handle($admin, $track);
    app(ArchiveTrack::class)->handle($admin, $track);
    app(ArchiveTrack::class)->handle($admin, $track);

    expect(Activity::query()->where('event', 'track_restored')->count())->toBe(0)
        ->and(Activity::query()->where('event', 'track_archived')->count())->toBe(1);
});

it('CA-004-15: an admin of A cannot create, edit, reorder, archive, restore or delete tracks of B', function (): void {
    [, , $adminA] = trackScenario();
    [$organizationB, $eventB, $adminB] = trackScenario();
    $trackOfB = trackCreate($adminB, $eventB, ['es' => 'Ciencia']);
    $trackOfB = Track::query()->withoutGlobalScopes()->findOrFail($trackOfB->id);

    expect(fn () => app(CreateTrack::class)->handle($adminA, $eventB, ['name' => ['es' => 'X']]))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateTrack::class)->handle($adminA, $trackOfB, ['name' => ['es' => 'X']]))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ReorderTracks::class)->handle($adminA, $eventB, [$trackOfB->id]))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ArchiveTrack::class)->handle($adminA, $trackOfB))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RestoreTrack::class)->handle($adminA, $trackOfB))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteTrack::class)->handle($adminA, $trackOfB))->toThrow(AuthorizationException::class)
        ->and(trackNames($eventB))->toBe(['Ciencia']);
});

it('CA-004-15: within the context of A, the event and the tracks of B are not found (404)', function (): void {
    [$organizationA] = trackScenario();
    [, $eventB, $adminB] = trackScenario();
    $trackOfB = trackCreate($adminB, $eventB, ['es' => 'Ciencia']);

    CurrentOrganization::set($organizationA);

    expect(fn () => Track::query()->findOrFail($trackOfB->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => Event::query()->findOrFail($eventB->id))->toThrow(ModelNotFoundException::class)
        ->and(Track::query()->count())->toBe(0);
});

it('CA-004-15: a member without tracks.manage gets 403 when modifying, and cannot see the section', function (): void {
    [$organization, $event, $reviewer] = trackScenario(OrganizationRole::Reviewer);
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $track = trackCreate($admin, $event, ['es' => 'Ciencia']);

    expect(fn () => app(CreateTrack::class)->handle($reviewer, $event, ['name' => ['es' => 'X']]))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateTrack::class)->handle($reviewer, $track, ['name' => ['es' => 'X']]))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ReorderTracks::class)->handle($reviewer, $event, [$track->id]))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ArchiveTrack::class)->handle($reviewer, $track))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RestoreTrack::class)->handle($reviewer, $track))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteTrack::class)->handle($reviewer, $track))->toThrow(AuthorizationException::class);

    Auth::login($reviewer);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    expect(TracksRelationManager::canViewForRecord($event, ViewEvent::class))->toBeFalse();
});

it('CA-004-15: an organizer (not only the admin) manages tracks', function (): void {
    [, $event, $organizer] = trackScenario(OrganizationRole::Organizer);

    expect(trackCreate($organizer, $event, ['es' => 'Ciencia'])->position)->toBe(1);
});

it('CA-004-16: the composite foreign key refuses a track whose organization is not the one of its event', function (): void {
    $event = Event::factory()->create();
    $other = Organization::factory()->create();
    $row = [
        'id' => (string) Str::uuid7(),
        'organization_id' => $other->id,
        'event_id' => $event->id,
        'name' => json_encode(['es' => 'Intruso']),
        'position' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    expect(fn () => DB::transaction(fn () => DB::table('tracks')->insert($row)))->toThrow(QueryException::class);

    $row['organization_id'] = $event->organization_id;

    expect(DB::transaction(fn () => DB::table('tracks')->insert($row)))->toBeTrue();
});

it('CA-004-12: creating, editing, reordering, archiving and restoring a track is audited with who, and old and new values', function (): void {
    [, $event, $admin] = trackScenario();

    $track = trackCreate($admin, $event, ['es' => 'Ciencia']);
    $other = trackCreate($admin, $event, ['es' => 'Arte']);
    app(UpdateTrack::class)->handle($admin, $track, ['name' => ['es' => 'Ciencias']]);
    app(ReorderTracks::class)->handle($admin, $event, [$other->id, $track->id]);
    app(ArchiveTrack::class)->handle($admin, $track);
    app(RestoreTrack::class)->handle($admin, $track);

    $records = Activity::query()->where('subject_id', $track->id)->orderBy('id')->get();
    $reorder = Activity::query()->where('event', 'track_reordered')->firstOrFail();

    expect($records->pluck('event')->all())->toBe(['track_created', 'track_updated', 'track_archived', 'track_restored'])
        ->and($records->pluck('causer_id')->unique()->all())->toBe([$admin->id])
        ->and($records[1]->properties['old']['name']['es'])->toBe('Ciencia')
        ->and($records[1]->properties['new']['name']['es'])->toBe('Ciencias')
        ->and($reorder->properties['old']['order'])->toBe([$track->id, $other->id])
        ->and($reorder->properties['new']['order'])->toBe([$other->id, $track->id]);
});

/** Panel de Filament con la sesión y el tenant del usuario. */
function trackPanelSession(Organization $organization, User $user): void
{
    Auth::login($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);
}

it('CA-004-12: in the panel an admin adds a track, edits it, moves it, archives and restores it', function (): void {
    [$organization, $event, $admin] = trackScenario();
    trackPanelSession($organization, $admin);

    $manager = Livewire::test(TracksRelationManager::class, ['ownerRecord' => $event, 'pageClass' => ViewEvent::class]);
    $manager->callAction(TestAction::make('createTrack')->table(), [
        'name' => ['es' => 'Ciencia', 'en' => 'Science'],
        'description' => ['es' => 'Investigación'],
    ])->assertHasNoFormErrors();
    $manager->callAction(TestAction::make('createTrack')->table(), ['name' => ['es' => 'Arte']])->assertHasNoFormErrors();

    expect(trackNames($event))->toBe(['Ciencia', 'Arte']);

    $science = CurrentOrganization::run($organization, fn () => Track::query()->where('event_id', $event->id)->orderBy('position')->firstOrFail());
    $art = CurrentOrganization::run($organization, fn () => Track::query()->where('event_id', $event->id)->orderByDesc('position')->firstOrFail());

    $manager->callAction(TestAction::make('moveUp')->table($art))->assertHasNoActionErrors();
    expect(trackNames($event))->toBe(['Arte', 'Ciencia']);

    $manager->callAction(TestAction::make('editTrack')->table($science), ['name' => ['es' => 'Ciencias']])->assertHasNoFormErrors();
    expect(trackNames($event))->toBe(['Arte', 'Ciencias']);

    $manager->callAction(TestAction::make('archiveTrack')->table($science));
    expect(CurrentOrganization::run($organization, fn () => $science->fresh()?->isArchived()))->toBeTrue();

    $manager->callAction(TestAction::make('restoreTrack')->table($science));
    expect(CurrentOrganization::run($organization, fn () => $science->fresh()?->isArchived()))->toBeFalse();
});

it('CA-004-13: the panel refuses a track without a name in the base language', function (): void {
    [$organization, $event, $admin] = trackScenario();
    trackPanelSession($organization, $admin);

    Livewire::test(TracksRelationManager::class, ['ownerRecord' => $event, 'pageClass' => ViewEvent::class])
        ->callAction(TestAction::make('createTrack')->table(), ['name' => ['en' => 'Science']])
        ->assertHasFormErrors();

    expect(trackNames($event))->toBe([]);
});

it('CA-004-12: an admin sees the tracks section of the event in the panel', function (): void {
    [$organization, $event, $admin] = trackScenario();
    $track = trackCreate($admin, $event, ['es' => 'Ciencia']);
    trackPanelSession($organization, $admin);

    expect(TracksRelationManager::canViewForRecord($event, ViewEvent::class))->toBeTrue();

    Livewire::test(TracksRelationManager::class, ['ownerRecord' => $event, 'pageClass' => ViewEvent::class])
        ->assertCanSeeTableRecords([$track]);
});

it('CA-004-12: moving a track up or down swaps it with its neighbour through the panel manager and the ends stay put', function (): void {
    [$organization, $event, $admin] = trackScenario();
    $a = trackCreate($admin, $event, ['es' => 'A']);
    $b = trackCreate($admin, $event, ['es' => 'B']);
    trackPanelSession($organization, $admin);

    $manager = Livewire::test(TracksRelationManager::class, ['ownerRecord' => $event, 'pageClass' => ViewEvent::class]);
    $manager->callAction(TestAction::make('moveUp')->table($a));
    $manager->callAction(TestAction::make('moveDown')->table($b));

    expect(trackNames($event))->toBe(['A', 'B'])
        ->and(Activity::query()->where('event', 'track_reordered')->count())->toBe(0);
});
