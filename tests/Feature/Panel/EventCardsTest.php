<?php

declare(strict_types=1);

use App\Actions\Events\CreateEvent as CreateEventAction;
use App\Enums\EventStatus;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Filament\Resources\Events\Tables\EventsTable;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

/*
 * Plan 015, F2: la lista de eventos es una cuadrícula de tarjetas, sin perder el
 * aislamiento por organización, el estado vacío ni los permisos de la tabla anterior.
 */

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

function cardsActAs(User $user, Organization $organization): void
{
    Auth::login($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);
}

/**
 * La tabla de la lista. `getTable()` viene del trait InteractsWithTable, que la clase base
 * de Livewire no declara.
 *
 * @param  Testable<Component>  $component
 */
function cardsTable(Testable $component): Table
{
    $instance = $component->instance();
    $table = method_exists($instance, 'getTable') ? $instance->getTable() : null;

    return $table instanceof Table ? $table : throw new LogicException('El componente no tiene tabla.');
}

/** @param  array<string, mixed>  $overrides */
function cardsEvent(Organization $organization, User $actor, array $overrides = []): Event
{
    return app(CreateEventAction::class)->handle($actor, $organization, array_merge([
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
    ], $overrides));
}

it('CA-015-4: lists the events as a grid of cards that grows with the screen', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    cardsEvent($organization, $admin);
    cardsActAs($admin, $organization);

    $grid = cardsTable(Livewire::test(ListEvents::class))->getContentGrid();

    expect($grid)->toBeArray()
        ->and($grid['default'])->toBe(1)
        ->and($grid['md'])->toBe(2)
        ->and($grid['xl'])->toBe(3);
});

it('CA-015-4: each card shows name, status, type and the dates in the event time zone', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $event = cardsEvent($organization, $admin);
    cardsActAs($admin, $organization);

    Livewire::test(ListEvents::class)
        ->assertCanSeeTableRecords([$event])
        ->assertSee('Congreso Parques 2027')
        ->assertSee(EventStatus::Draft->getLabel())
        ->assertSee(EventsTable::displayDates($event));
});

it('CA-015-4: a click on the card opens the event', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $event = cardsEvent($organization, $admin);
    cardsActAs($admin, $organization);

    $table = cardsTable(Livewire::test(ListEvents::class));

    expect($table->getRecordUrl($event))->toBe(EventResource::getUrl('view', ['record' => $event]));
});

it('CA-015-4: each card carries the class of its status for the top edge', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $event = cardsEvent($organization, $admin);
    cardsActAs($admin, $organization);

    $table = cardsTable(Livewire::test(ListEvents::class));

    expect($table->getRecordClasses($event))->toContain('eventos-card-draft');
});

it('CA-015-5: the cards of an organization never include the events of another', function (): void {
    $organizationA = Memberships::organization();
    $organizationB = Memberships::organization();
    $adminA = Memberships::attach(User::factory()->create(), $organizationA, OrganizationRole::OrgAdmin);
    $adminB = Memberships::attach(User::factory()->create(), $organizationB, OrganizationRole::OrgAdmin);
    $own = cardsEvent($organizationA, $adminA, ['slug' => 'propio']);
    $foreign = cardsEvent($organizationB, $adminB, [
        'slug' => 'ajeno',
        'name' => ['es' => 'Evento de otra organización'],
        'locales' => ['es'],
    ]);
    cardsActAs($adminA, $organizationA);

    Livewire::test(ListEvents::class)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$foreign])
        ->assertDontSee('Evento de otra organización');
});

it('CA-015-6: with no events the empty state invites to create the first one', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    cardsActAs($admin, $organization);

    Livewire::test(ListEvents::class)
        ->assertCountTableRecords(0)
        ->assertSee(__('events.table.empty_heading'))
        ->assertSee(__('events.table.empty_description'));
});

it('CA-015-7: an organizer without the publish permission gets no publish action on the card', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $organizer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer);
    $event = cardsEvent($organization, $admin);
    cardsActAs($organizer, $organization);

    Livewire::test(ListEvents::class)
        ->assertCanSeeTableRecords([$event])
        ->assertActionHidden(TestAction::make('publish')->table($event))
        ->assertActionHidden(TestAction::make('archive')->table($event));

    expect(Gate::forUser($organizer)->denies('publish', $event))->toBeTrue()
        ->and($event->fresh()->status)->toBe(EventStatus::Draft);
});

it('CA-015-7: whoever may publish still gets the action on the card', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $event = cardsEvent($organization, $admin);
    cardsActAs($admin, $organization);

    Livewire::test(ListEvents::class)
        ->assertActionVisible(TestAction::make('publish')->table($event));
});
