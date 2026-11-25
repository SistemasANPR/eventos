<?php

declare(strict_types=1);

use App\Actions\Program\CreateRoom;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\ManageEventActivities;
use App\Filament\Resources\Events\Pages\ManageEventRooms;
use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Organization;
use App\Models\Room;
use App\Models\User;
use App\Support\Files\MediaDisk;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/form-helpers.php';

/*
 * Plan 031, F2: «Salas» como sección del evento, a la que se llega desde Actividades. Cada prueba
 * usa un solo rol: la caché de permisos de Spatie se comparte dentro del proceso. Estas pruebas
 * corren con APP_ENV=testing.
 */

beforeEach(function (): void {
    Storage::fake(MediaDisk::name());
    app()->setLocale('es');
});

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/** @return Testable<ManageEventRooms> */
function roomsPage(Organization $organization, User $user, Event $event): Testable
{
    Auth::login($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    return Livewire::test(ManageEventRooms::class, ['record' => $event->id]);
}

function panelRoom(User $actor, Event $event, string $name): Room
{
    return app(CreateRoom::class)->handle($actor, $event, ['name' => $name]);
}

it('CA-031-5: the empty list shows its empty state and the admin creates, renames and deletes a room', function (): void {
    [$organization, $event, $admin] = formScenario();

    $component = roomsPage($organization, $admin, $event)->assertSee(__('program.rooms.empty_heading'));

    $component->callAction('createRoom', ['name' => 'Auditorio'])->assertHasNoActionErrors();
    $room = Room::query()->where('event_id', $event->id)->firstOrFail();

    $component->assertCanSeeTableRecords([$room])
        ->callAction(TestAction::make('editRoom')->table($room), ['name' => 'Principal'])
        ->assertHasNoActionErrors();

    expect($room->refresh()->name)->toBe('Principal');

    $component->callAction(TestAction::make('deleteRoom')->table($room));

    expect(Room::query()->count())->toBe(0);
});

it('CA-031-5: a repeated name is refused with a notification and nothing is created', function (): void {
    [$organization, $event, $admin] = formScenario();
    panelRoom($admin, $event, 'Auditorio');

    roomsPage($organization, $admin, $event)
        ->callAction('createRoom', ['name' => 'AUDITORIO'])
        ->assertNotified(__('program.rooms.failed'));

    expect(Room::query()->count())->toBe(1);
});

it('CA-031-7: deleting a room with activities is refused with a notification and the room stays', function (): void {
    [$organization, $event, $admin] = formScenario();
    $room = panelRoom($admin, $event, 'Auditorio');
    CurrentOrganization::run($organization, fn () => EventActivity::factory()->scheduled()->create(['event_id' => $event->id, 'room_id' => $room->id]));

    roomsPage($organization, $admin, $event)
        ->callAction(TestAction::make('deleteRoom')->table($room))
        ->assertNotified(__('program.rooms.failed'));

    expect(Room::query()->count())->toBe(1);
});

it('CA-031-8: the move actions reorder the rooms and the list follows the position', function (): void {
    [$organization, $event, $admin] = formScenario();
    $a = panelRoom($admin, $event, 'A');
    $b = panelRoom($admin, $event, 'B');

    roomsPage($organization, $admin, $event)
        ->assertCanSeeTableRecords([$a, $b], inOrder: true)
        ->callAction(TestAction::make('moveRoomUp')->table($b))
        ->assertCanSeeTableRecords([$b, $a], inOrder: true);

    expect($b->refresh()->position)->toBe(1)
        ->and($a->refresh()->position)->toBe(2);
});

it('CA-031-8: the list shows how many activities use each room', function (): void {
    [$organization, $event, $admin] = formScenario();
    $room = panelRoom($admin, $event, 'Auditorio');
    CurrentOrganization::run($organization, function () use ($event, $room): void {
        EventActivity::factory()->count(3)->scheduled()->create(['event_id' => $event->id, 'room_id' => $room->id]);
    });

    roomsPage($organization, $admin, $event)->assertTableColumnStateSet('activities_count', 3, $room);
});

it('CA-031-6: the list shows only the rooms of its event', function (): void {
    [$organization, $event, $admin] = formScenario();
    $otherEvent = Event::factory()->create(['organization_id' => $organization->id]);
    $own = panelRoom($admin, $event, 'Propia');
    $ofOtherEvent = panelRoom($admin, $otherEvent, 'De otro evento');

    roomsPage($organization, $admin, $event)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$ofOtherEvent]);
});

it('CA-031-6: the organizer creates and edits but is not offered the delete action', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $room = panelRoom($admin, $event, 'Auditorio');

    roomsPage($organization, $organizer, $event)
        ->assertActionVisible('createRoom')
        ->assertActionVisible(TestAction::make('editRoom')->table($room))
        ->assertActionHidden(TestAction::make('deleteRoom')->table($room));
});

it('CA-031-6: a reviewer has no access to the rooms page, by class or by URL', function (): void {
    [$organization, $event, $reviewer] = formScenario(OrganizationRole::Reviewer);

    expect(ManageEventRooms::canAccess(['record' => $event]))->toBeFalse();

    $this->actingAs($reviewer)
        ->get(EventResource::getUrl('rooms', ['record' => $event], panel: 'admin', tenant: $organization))
        ->assertForbidden();
});

it('CA-031-6: the rooms page of an event of another organization answers 404', function (): void {
    [$organization, , $admin] = formScenario();
    $foreign = Event::factory()->create();

    $this->actingAs($admin)
        ->get(EventResource::getUrl('rooms', ['record' => $foreign], panel: 'admin', tenant: $organization))
        ->assertNotFound();
});

it('CA-031-5: the activities page links to the rooms page with the count of rooms', function (): void {
    [$organization, $event, $admin] = formScenario();
    panelRoom($admin, $event, 'Auditorio');
    panelRoom($admin, $event, 'Sala B');

    Auth::login($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    Livewire::test(ManageEventActivities::class, ['record' => $event->id])
        ->assertActionVisible('rooms')
        ->assertSee(EventResource::getUrl('rooms', ['record' => $event], panel: 'admin', tenant: $organization), false);
});
