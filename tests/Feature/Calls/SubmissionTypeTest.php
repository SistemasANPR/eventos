<?php

declare(strict_types=1);

use App\Actions\Calls\ArchiveSubmissionType;
use App\Actions\Calls\CreateCallForSubmissions;
use App\Actions\Calls\CreateSubmissionType;
use App\Actions\Calls\MoveSubmissionType;
use App\Actions\Calls\RenameSubmissionType;
use App\Actions\Calls\RestoreSubmissionType;
use App\Actions\Calls\SeedDefaultSubmissionTypes;
use App\Actions\Calls\UpdateCallForSubmissions;
use App\Actions\Events\CreateEvent;
use App\Enums\MoveDirection;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Resources\Events\RelationManagers\CallsRelationManager;
use App\Filament\Resources\Events\RelationManagers\SubmissionTypesRelationManager;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\Organization;
use App\Models\SubmissionType;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Memberships;
use Tests\Support\SubmissionTypes;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/** @return array{0: Organization, 1: Event, 2: User} organización con roles, evento (es, en) con sus tipos por defecto y administrador */
function typeScenario(OrganizationRole $role = OrganizationRole::OrgAdmin): array
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

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function typeCallData(Event $event, SubmissionType $type, array $overrides = []): array
{
    return array_merge([
        'submission_type_id' => $type->id,
        'title' => ['es' => 'Convocatoria de prueba'],
        'locales' => ['es'],
        'opens_at' => '2027-01-10 09:00',
        'closes_at' => '2027-02-15 23:00',
    ], $overrides);
}

/** @return list<string> nombres en español, en el orden de la lista del evento */
function typeNames(Event $event): array
{
    $organization = Organization::query()->findOrFail($event->organization_id);

    return CurrentOrganization::run($organization, fn (): array => SubmissionType::query()
        ->where('event_id', $event->id)->orderBy('position')->get()
        ->map(fn (SubmissionType $type): string => $type->getTranslation('name', 'es'))->all());
}

it('CA-004-29: a new event is born with Session, Panel, Poster and Workshop, active and in that order, in es, en and pt', function (): void {
    [$organization, $event] = typeScenario();
    CurrentOrganization::set($organization);

    $types = SubmissionType::query()->where('event_id', $event->id)->orderBy('position')->get();

    expect($types->map(fn (SubmissionType $type): string => $type->getTranslation('name', 'es'))->all())->toBe(['Sesión', 'Panel', 'Póster', 'Taller'])
        ->and($types->map(fn (SubmissionType $type): string => $type->getTranslation('name', 'en'))->all())->toBe(['Session', 'Panel', 'Poster', 'Workshop'])
        ->and($types->map(fn (SubmissionType $type): string => $type->getTranslation('name', 'pt'))->all())->toBe(['Sessão', 'Painel', 'Pôster', 'Oficina'])
        ->and($types->pluck('position')->all())->toBe([1, 2, 3, 4])
        ->and($types->every(fn (SubmissionType $type): bool => ! $type->isArchived()))->toBeTrue();
});

it('CA-004-29: seeding the defaults twice does not duplicate them', function (): void {
    [$organization, $event] = typeScenario();
    CurrentOrganization::set($organization);

    app(SeedDefaultSubmissionTypes::class)->handle($event);

    expect(SubmissionType::query()->where('event_id', $event->id)->count())->toBe(4);
});

it('CA-004-30: an admin adds a type at the end of the list of that event only', function (): void {
    [$organization, $event, $admin] = typeScenario();
    $otherEvent = Event::factory()->create(['organization_id' => $organization->id]);

    $type = app(CreateSubmissionType::class)->handle($admin, $event, ['name' => ['es' => 'Conferencia magistral', 'en' => 'Keynote']]);

    expect(typeNames($event))->toBe(['Sesión', 'Panel', 'Póster', 'Taller', 'Conferencia magistral'])
        ->and($type->position)->toBe(5)
        ->and(typeNames($otherEvent))->toBe([]);
});

it('CA-004-30: a type without a name in the base language of the event is refused', function (array $name): void {
    [, $event, $admin] = typeScenario();

    expect(fn () => app(CreateSubmissionType::class)->handle($admin, $event, ['name' => $name]))->toThrow(ValidationException::class);
    expect(typeNames($event))->toBe(['Sesión', 'Panel', 'Póster', 'Taller']);
})->with([
    'only another language' => [['en' => 'Keynote']],
    'blank base language' => [['es' => '   ', 'en' => 'Keynote']],
    'nothing' => [[]],
]);

it('CA-004-30: a language without a translation falls back to the base language when shown', function (): void {
    [$organization, $event, $admin] = typeScenario();
    $type = app(CreateSubmissionType::class)->handle($admin, $event, ['name' => ['es' => 'Conferencia']]);
    CurrentOrganization::set($organization);

    expect($type->fresh()->label('en'))->toBe('Conferencia');
});

it('CA-004-30: a type added to an event can be chosen in its calls', function (): void {
    [, $event, $admin] = typeScenario();
    $type = app(CreateSubmissionType::class)->handle($admin, $event, ['name' => ['es' => 'Conferencia']]);

    $call = app(CreateCallForSubmissions::class)->handle($admin, $event, typeCallData($event, $type));

    expect($call->submission_type_id)->toBe($type->id);
});

it('CA-004-31: renaming a type is shown by the calls that use it', function (): void {
    [$organization, $event, $admin] = typeScenario();
    $type = CurrentOrganization::run($organization, fn () => SubmissionType::query()->where('event_id', $event->id)->orderBy('position')->firstOrFail());
    $call = app(CreateCallForSubmissions::class)->handle($admin, $event, typeCallData($event, $type));

    app(RenameSubmissionType::class)->handle($admin, $type, ['name' => ['es' => 'Ponencia']]);
    CurrentOrganization::set($organization);

    expect($call->fresh()->submissionType->label('es'))->toBe('Ponencia');
});

it('CA-004-31: moving a type up or down swaps it with its neighbour, and the ends stay put', function (): void {
    [$organization, $event, $admin] = typeScenario();
    $types = CurrentOrganization::run($organization, fn () => SubmissionType::query()->where('event_id', $event->id)->orderBy('position')->get());
    [$session, $panel, , $workshop] = [$types[0], $types[1], $types[2], $types[3]];

    app(MoveSubmissionType::class)->handle($admin, $panel, MoveDirection::Up);
    expect(typeNames($event))->toBe(['Panel', 'Sesión', 'Póster', 'Taller']);

    app(MoveSubmissionType::class)->handle($admin, $session->fresh(), MoveDirection::Down);
    expect(typeNames($event))->toBe(['Panel', 'Póster', 'Sesión', 'Taller']);

    app(MoveSubmissionType::class)->handle($admin, $panel->fresh(), MoveDirection::Up);
    app(MoveSubmissionType::class)->handle($admin, $workshop->fresh(), MoveDirection::Down);

    expect(typeNames($event))->toBe(['Panel', 'Póster', 'Sesión', 'Taller']);
});

it('CA-004-32: an archived type is not offered to new calls or to switch to, but the calls that have it keep it', function (): void {
    [$organization, $event, $admin] = typeScenario();
    $workshop = CurrentOrganization::run($organization, fn () => SubmissionType::query()->where('event_id', $event->id)->orderByDesc('position')->firstOrFail());
    $kept = app(CreateCallForSubmissions::class)->handle($admin, $event, typeCallData($event, $workshop));

    app(ArchiveSubmissionType::class)->handle($admin, $workshop);

    expect(fn () => app(CreateCallForSubmissions::class)->handle($admin, $event, typeCallData($event, $workshop)))->toThrow(ValidationException::class);

    $other = CurrentOrganization::run($organization, fn () => SubmissionType::query()->where('event_id', $event->id)->orderBy('position')->firstOrFail());
    $moved = app(CreateCallForSubmissions::class)->handle($admin, $event, typeCallData($event, $other));

    expect(fn () => app(UpdateCallForSubmissions::class)->handle($admin, $moved, typeCallData($event, $workshop)))->toThrow(ValidationException::class);

    // La convocatoria que ya lo tenía se puede seguir editando sin cambiar de tipo.
    $edited = app(UpdateCallForSubmissions::class)->handle($admin, $kept, typeCallData($event, $workshop, ['title' => ['es' => 'Otro título']]));
    expect($edited->submission_type_id)->toBe($workshop->id);
});

it('CA-004-32: restoring an archived type offers it again', function (): void {
    [, $event, $admin] = typeScenario();
    $type = SubmissionTypes::for($event, 'Mesa');

    app(ArchiveSubmissionType::class)->handle($admin, $type);
    expect($type->fresh()->isArchived())->toBeTrue();

    app(RestoreSubmissionType::class)->handle($admin, $type);

    expect($type->fresh()->isArchived())->toBeFalse()
        ->and(app(CreateCallForSubmissions::class)->handle($admin, $event, typeCallData($event, $type)))->toBeInstanceOf(CallForSubmissions::class);
});

it('CA-004-33: a type of another event or of another organization cannot be used in a call', function (): void {
    [$organization, $event, $admin] = typeScenario();
    $siblingEvent = Event::factory()->create(['organization_id' => $organization->id]);
    $organizationB = Memberships::organization();
    $eventOfB = Event::factory()->create(['organization_id' => $organizationB->id]);

    foreach ([SubmissionTypes::for($siblingEvent), SubmissionTypes::for($eventOfB)] as $foreign) {
        expect(fn () => app(CreateCallForSubmissions::class)->handle($admin, $event, typeCallData($event, $foreign)))
            ->toThrow(ValidationException::class);
    }
});

it('CA-004-33: an admin of A cannot create, rename, move or archive types of B', function (): void {
    [, , $adminA] = typeScenario();
    $organizationB = Memberships::organization();
    $eventOfB = Event::factory()->create(['organization_id' => $organizationB->id]);
    $typeOfB = SubmissionTypes::for($eventOfB);

    expect(fn () => app(CreateSubmissionType::class)->handle($adminA, $eventOfB, ['name' => ['es' => 'X']]))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RenameSubmissionType::class)->handle($adminA, $typeOfB, ['name' => ['es' => 'X']]))->toThrow(AuthorizationException::class)
        ->and(fn () => app(MoveSubmissionType::class)->handle($adminA, $typeOfB, MoveDirection::Up))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ArchiveSubmissionType::class)->handle($adminA, $typeOfB))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RestoreSubmissionType::class)->handle($adminA, $typeOfB))->toThrow(AuthorizationException::class);
});

it('CA-004-33: a member without calls.manage cannot manage types, and one with calls.view can see them', function (): void {
    [$organization, $event, $reviewer] = typeScenario(OrganizationRole::Reviewer);
    $type = SubmissionTypes::for($event, 'Mesa');

    expect(fn () => app(CreateSubmissionType::class)->handle($reviewer, $event, ['name' => ['es' => 'X']]))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RenameSubmissionType::class)->handle($reviewer, $type, ['name' => ['es' => 'X']]))->toThrow(AuthorizationException::class)
        ->and(fn () => app(MoveSubmissionType::class)->handle($reviewer, $type, MoveDirection::Down))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ArchiveSubmissionType::class)->handle($reviewer, $type))->toThrow(AuthorizationException::class);

    CurrentOrganization::set($organization);
    $reviewer->unsetRelation('roles')->unsetRelation('permissions');

    expect($reviewer->can('viewAny', SubmissionType::class))->toBe($reviewer->can('viewAny', CallForSubmissions::class))
        ->and($reviewer->can('create', [SubmissionType::class, $event]))->toBeFalse();
});

it('CA-004-34: the composite foreign keys refuse a type whose organization is not the one of its event', function (): void {
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

    expect(fn () => DB::transaction(fn () => DB::table('submission_types')->insert($row)))->toThrow(QueryException::class);
});

it('CA-004-34: the database refuses a call that uses the type of another organization', function (): void {
    $event = Event::factory()->create();
    $organizationB = Organization::factory()->create();
    $typeOfB = SubmissionTypes::for(Event::factory()->create(['organization_id' => $organizationB->id]));

    $row = [
        'id' => (string) Str::uuid7(),
        'organization_id' => $event->organization_id,
        'event_id' => $event->id,
        'slug' => 'convocatoria',
        'submission_type_id' => $typeOfB->id,
        'title' => json_encode(['es' => 'Convocatoria']),
        'locales' => json_encode(['es']),
        'opens_at' => '2027-01-10 15:00:00',
        'closes_at' => '2027-02-15 23:00:00',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    expect(fn () => DB::transaction(fn () => DB::table('call_for_submissions')->insert($row)))->toThrow(QueryException::class);
});

it('CA-004-34: the database never deletes a type that a call uses', function (): void {
    [, $event, $admin] = typeScenario();
    $type = SubmissionTypes::for($event);
    app(CreateCallForSubmissions::class)->handle($admin, $event, typeCallData($event, $type));

    expect(fn () => DB::transaction(fn () => DB::table('submission_types')->where('id', $type->id)->delete()))->toThrow(QueryException::class);
});

it('CA-004-35: creating, renaming, moving and archiving a type is audited with who, and old and new values', function (): void {
    [, $event, $admin] = typeScenario();

    $type = app(CreateSubmissionType::class)->handle($admin, $event, ['name' => ['es' => 'Conferencia']]);
    app(RenameSubmissionType::class)->handle($admin, $type, ['name' => ['es' => 'Magistral']]);
    app(MoveSubmissionType::class)->handle($admin, $type, MoveDirection::Up);
    app(ArchiveSubmissionType::class)->handle($admin, $type);
    app(RestoreSubmissionType::class)->handle($admin, $type);

    $events = Activity::query()->where('subject_id', $type->id)->orderBy('id')->get();

    expect($events->pluck('event')->all())->toBe(['submission_type_created', 'submission_type_renamed', 'submission_type_moved', 'submission_type_archived', 'submission_type_restored'])
        ->and($events->pluck('causer_id')->unique()->all())->toBe([$admin->id])
        ->and($events[1]->properties['old']['es'])->toBe('Conferencia')
        ->and($events[1]->properties['new']['es'])->toBe('Magistral')
        ->and($events[2]->properties['old']['position'])->toBe(5)
        ->and($events[2]->properties['new']['position'])->toBe(4);
});

it('CA-004-35: moving the first type up leaves no audit record because nothing changed', function (): void {
    [$organization, $event, $admin] = typeScenario();
    $first = CurrentOrganization::run($organization, fn () => SubmissionType::query()->where('event_id', $event->id)->orderBy('position')->firstOrFail());

    app(MoveSubmissionType::class)->handle($admin, $first, MoveDirection::Up);

    expect(Activity::query()->where('event', 'submission_type_moved')->count())->toBe(0);
});

/** Panel de Filament con la sesión y el tenant del usuario, como en las pruebas de convocatorias. */
function typePanelSession(Organization $organization, User $user): void
{
    Auth::login($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);
}

it('CA-004-36: from the call form an admin creates a type for the event and it ends up selected', function (): void {
    [$organization, $event, $admin] = typeScenario();
    typePanelSession($organization, $admin);

    Livewire::test(CallsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => ViewEvent::class])
        ->callAction(
            [TestAction::make('createCall')->table(), TestAction::make('createOption')->schemaComponent('submission_type_id')],
            ['name' => ['es' => 'Conferencia magistral']],
        )
        ->assertHasNoFormErrors();

    expect(typeNames($event))->toBe(['Sesión', 'Panel', 'Póster', 'Taller', 'Conferencia magistral']);
});

it('CA-004-36: a member without calls.manage does not get the create-type access in the call form', function (): void {
    [$organization, $event, $reviewer] = typeScenario(OrganizationRole::Reviewer);
    typePanelSession($organization, $reviewer);

    expect(Gate::forUser($reviewer)->allows('create', [SubmissionType::class, $event]))->toBeFalse();
});

it('CA-004-30: an admin sees the types of the event in the panel, and can add one from the section', function (): void {
    [$organization, $event, $admin] = typeScenario();
    typePanelSession($organization, $admin);

    $manager = Livewire::test(SubmissionTypesRelationManager::class, ['ownerRecord' => $event, 'pageClass' => ViewEvent::class]);
    $manager->assertCanSeeTableRecords(CurrentOrganization::run($organization, fn () => SubmissionType::query()->where('event_id', $event->id)->get()));

    $manager->callAction(TestAction::make('createType')->table(), ['name' => ['es' => 'Conferencia']])->assertHasNoFormErrors();

    expect(typeNames($event))->toContain('Conferencia');
});

it('CA-004-33: a reviewer does not see the types section at all', function (): void {
    [$organization, $event, $reviewer] = typeScenario(OrganizationRole::Reviewer);
    typePanelSession($organization, $reviewer);

    expect(SubmissionTypesRelationManager::canViewForRecord($event, ViewEvent::class))->toBeFalse();
});
