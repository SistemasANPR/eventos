<?php

declare(strict_types=1);

use App\Actions\Events\CreateEvent as CreateEventAction;
use App\Actions\Events\PublishEvent as PublishEventAction;
use App\Enums\EventStatus;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/** Deja el panel `admin` con la organización como tenant y como contexto, como lo hace el middleware real. */
function actInPanel(User $user, Organization $organization): void
{
    Auth::login($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function panelEventData(array $overrides = []): array
{
    return array_merge([
        'name' => ['es' => 'Congreso Parques 2027', 'en' => 'Parks Congress 2027'],
        'description' => ['es' => 'Descripción'],
        'slug' => 'congreso-2027',
        'type' => 'congress',
        'starts_at' => '2027-03-10 09:00',
        'ends_at' => '2027-03-12 17:00',
        'timezone' => 'America/Mexico_City',
        'venue_name' => 'Centro de Convenciones',
        'venue_address' => 'Av. Reforma 1',
        'city' => 'Ciudad de México',
        'locales' => ['es', 'en'],
        'default_locale' => 'es',
        'currency' => 'MXN',
    ], $overrides);
}

/** @param  array<string, mixed>  $overrides */
function eventFor(Organization $organization, User $actor, array $overrides = []): Event
{
    return app(CreateEventAction::class)->handle($actor, $organization, panelEventData($overrides));
}

it('CA-003-10: opens the event panel by its id in the URL', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $event = eventFor($organization, $admin);

    $this->actingAs($admin)->get("/admin/{$organization->slug}/events/{$event->id}")->assertOk();
    $this->actingAs($admin)->get("/admin/{$organization->slug}/events/{$event->id}/edit")->assertOk();
});

it('CA-003-5: an event of another organization answers 404 in the URL of this one', function (): void {
    $organizationA = Memberships::organization();
    $organizationB = Memberships::organization();
    $adminA = Memberships::attach(User::factory()->create(), $organizationA, OrganizationRole::OrgAdmin);
    $adminB = Memberships::attach(User::factory()->create(), $organizationB, OrganizationRole::OrgAdmin);
    $eventOfB = eventFor($organizationB, $adminB);

    $this->actingAs($adminA)->get("/admin/{$organizationA->slug}/events/{$eventOfB->id}")->assertNotFound();
    $this->actingAs($adminA)->get("/admin/{$organizationA->slug}/events/{$eventOfB->id}/edit")->assertNotFound();
});

it('CA-003-10: the selector lists only the events of the organization', function (): void {
    $organizationA = Memberships::organization();
    $organizationB = Memberships::organization();
    $adminA = Memberships::attach(User::factory()->create(), $organizationA, OrganizationRole::OrgAdmin);
    $adminB = Memberships::attach(User::factory()->create(), $organizationB, OrganizationRole::OrgAdmin);
    $first = eventFor($organizationA, $adminA, ['slug' => 'primero']);
    $second = eventFor($organizationA, $adminA, ['slug' => 'segundo']);
    $foreign = eventFor($organizationB, $adminB, ['slug' => 'ajeno']);

    actInPanel($adminA, $organizationA);

    Livewire::test(ListEvents::class)
        ->assertCanSeeTableRecords([$first, $second])
        ->assertCanNotSeeTableRecords([$foreign]);
});

it('CA-003-10: with no event yet the list asks to create or choose one instead of picking any', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);

    actInPanel($admin, $organization);

    Livewire::test(ListEvents::class)
        ->assertSee(__('events.table.empty_heading'))
        ->assertCountTableRecords(0);
});

it('CA-003-6: a reviewer cannot open the events of the panel', function (): void {
    $organization = Memberships::organization();
    $reviewer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);

    $response = $this->actingAs($reviewer)->get("/admin/{$organization->slug}/events");

    expect($response->status())->toBeIn([403, 404]);
});

it('CA-003-1: an organizer creates an event from the panel in draft and in UTC', function (): void {
    $organization = Memberships::organization();
    $organizer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer);
    actInPanel($organizer, $organization);

    Livewire::test(CreateEvent::class)
        ->fillForm(panelEventData())
        ->call('create')
        ->assertHasNoFormErrors();

    $event = Event::query()->sole();

    expect($event->status)->toBe(EventStatus::Draft)
        ->and($event->starts_at->utc()->format('Y-m-d H:i'))->toBe('2027-03-10 15:00')
        ->and($event->getTranslation('name', 'en'))->toBe('Parks Congress 2027');
});

it('CA-003-3: shows the error of each invalid field and saves nothing', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    actInPanel($admin, $organization);

    Livewire::test(CreateEvent::class)
        ->fillForm(panelEventData(['ends_at' => '2027-03-01 09:00', 'timezone' => 'America/Mexico_City']))
        ->call('create')
        ->assertHasFormErrors(['ends_at']);

    expect(Event::query()->count())->toBe(0);
});

it('CA-003-2: shows the error when the slug is repeated in the organization', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    eventFor($organization, $admin);
    actInPanel($admin, $organization);

    Livewire::test(CreateEvent::class)
        ->fillForm(panelEventData())
        ->call('create')
        ->assertHasFormErrors(['slug']);

    expect(Event::query()->count())->toBe(1);
});

it('CA-003-4: the edit form shows the instants in the time zone of the event', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $event = eventFor($organization, $admin);
    actInPanel($admin, $organization);

    Livewire::test(EditEvent::class, ['record' => $event->id])
        ->assertFormSet(['starts_at' => '2027-03-10 09:00', 'ends_at' => '2027-03-12 17:00', 'timezone' => 'America/Mexico_City']);
});

it('CA-003-4: saving the edit form keeps the same UTC instants when nothing changes', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $event = eventFor($organization, $admin);
    actInPanel($admin, $organization);

    Livewire::test(EditEvent::class, ['record' => $event->id])
        ->fillForm(['city' => 'Puebla'])
        ->call('save')
        ->assertHasNoFormErrors();

    $event->refresh();

    expect($event->city)->toBe('Puebla')
        ->and($event->starts_at->utc()->format('Y-m-d H:i'))->toBe('2027-03-10 15:00');
});

it('CA-003-6: an admin publishes and archives from the panel', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $event = eventFor($organization, $admin);
    actInPanel($admin, $organization);

    Livewire::test(EditEvent::class, ['record' => $event->id])->callAction('publish');

    expect($event->fresh()->status)->toBe(EventStatus::Published);

    Livewire::test(EditEvent::class, ['record' => $event->id])->callAction('archive');

    expect($event->fresh()->status)->toBe(EventStatus::Archived);
});

it('CA-003-6: an organizer sees no publish or archive action and cannot call it', function (): void {
    $organization = Memberships::organization();
    $organizer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer);
    $event = eventFor($organization, $organizer);
    actInPanel($organizer, $organization);

    Livewire::test(EditEvent::class, ['record' => $event->id])
        ->assertActionHidden('publish')
        ->assertActionHidden('archive');

    expect($event->fresh()->status)->toBe(EventStatus::Draft);
});

it('CA-003-9: the form asks to confirm before changing the slug of a published event', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $event = app(PublishEventAction::class)->handle($admin, eventFor($organization, $admin));
    actInPanel($admin, $organization);

    Livewire::test(EditEvent::class, ['record' => $event->id])
        ->fillForm(['slug' => 'nuevo-identificador'])
        ->call('save')
        ->assertHasFormErrors(['slug']);

    expect($event->fresh()->slug)->toBe('congreso-2027');

    Livewire::test(EditEvent::class, ['record' => $event->id])
        ->fillForm(['slug' => 'nuevo-identificador', 'confirm_slug_change' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($event->fresh()->slug)->toBe('nuevo-identificador');
});

it('CA-003-8: the panel offers no way to delete an event', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $event = eventFor($organization, $admin);
    actInPanel($admin, $organization);

    Livewire::test(EditEvent::class, ['record' => $event->id])
        ->assertActionDoesNotExist('delete')
        ->assertActionDoesNotExist('forceDelete');

    expect(Gate::forUser($admin)->allows('delete', $event))->toBeFalse();
});
