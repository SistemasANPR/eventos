<?php

declare(strict_types=1);

use App\Actions\Program\CreateRoom;
use App\Actions\Program\UpdateRoom;
use App\Enums\OrganizationRole;
use App\Enums\RoomKind;
use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Room;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

/*
 * Plan 035, F2: lugares del evento. La sala del plan 031 gana tipo (`room` o `external`), capacidad, interruptor
 * de capacidad pública, dirección y mapa, todo opcional salvo el nombre y el tipo (por defecto `room`).
 */

afterEach(function (): void {
    CurrentOrganization::forget();
});

/** @return array{0: Event, 1: User} evento de una organización nueva y un usuario con el rol indicado en ella */
function placeActionScenario(OrganizationRole $role = OrganizationRole::OrgAdmin): array
{
    $organization = Memberships::organization();
    $user = Memberships::attach(User::factory()->create(), $organization, $role);

    return [Event::factory()->create(['organization_id' => $organization->id]), $user];
}

function placeReload(Room $room): Room
{
    return CurrentOrganization::run($room->organization, fn (): Room => Room::query()->findOrFail($room->id));
}

it('CA-035-3: a place is created with name, kind and the optional capacity, address and map', function (): void {
    [$event, $admin] = placeActionScenario();

    $park = app(CreateRoom::class)->handle($admin, $event, [
        'name' => 'Parque Lineal',
        'kind' => 'external',
        'capacity' => 40,
        'address' => 'Entrada norte, junto al quiosco',
        'map_url' => 'https://maps.example.test/parque-lineal',
    ]);
    $plain = app(CreateRoom::class)->handle($admin, $event, ['name' => 'Auditorio']);

    $park = placeReload($park);
    $plain = placeReload($plain);

    expect($park->kind)->toBe(RoomKind::External)
        ->and($park->capacity)->toBe(40)
        ->and($park->address)->toBe('Entrada norte, junto al quiosco')
        ->and($park->map_url)->toBe('https://maps.example.test/parque-lineal')
        ->and($plain->kind)->toBe(RoomKind::Room)
        ->and($plain->capacity)->toBeNull()
        ->and($plain->address)->toBeNull()
        ->and($plain->map_url)->toBeNull()
        ->and($plain->capacity_is_public)->toBeFalse();
});

it('CA-035-3: a kind other than room or external is refused and nothing is created', function (): void {
    [$event, $admin] = placeActionScenario();

    expect(fn () => app(CreateRoom::class)->handle($admin, $event, ['name' => 'Raro', 'kind' => 'parque']))
        ->toThrow(ValidationException::class);

    expect(CurrentOrganization::run($event->organization, fn () => Room::query()->where('event_id', $event->id)->count()))->toBe(0);
});

it('CA-035-4: the capacity is a whole number from 1 to 100000, or empty', function (mixed $capacity, bool $valid): void {
    [$event, $admin] = placeActionScenario();
    $create = fn () => app(CreateRoom::class)->handle($admin, $event, ['name' => 'Lugar', 'capacity' => $capacity]);

    if ($valid) {
        $room = placeReload($create());
        expect($room->capacity)->toBe(is_numeric($capacity) ? (int) $capacity : null);

        return;
    }

    expect($create)->toThrow(ValidationException::class);
})->with([
    'one' => [1, true],
    'numeric text' => ['25', true],
    'upper limit' => [100000, true],
    'missing' => [null, true],
    'empty text' => ['', true],
    'zero' => [0, false],
    'negative' => [-3, false],
    'decimal' => ['1.5', false],
    'over the limit' => [100001, false],
    'words' => ['muchas', false],
]);

it('CA-035-5: the map must be an https link of up to 2048 characters and the address up to 255', function (string $field, string $value, bool $valid): void {
    [$event, $admin] = placeActionScenario();
    $create = fn () => app(CreateRoom::class)->handle($admin, $event, ['name' => 'Lugar', $field => $value]);

    if ($valid) {
        expect(placeReload($create())->{$field})->toBe($value);

        return;
    }

    expect($create)->toThrow(ValidationException::class);
})->with([
    'https map' => ['map_url', 'https://maps.example.test/a', true],
    'http map' => ['map_url', 'http://maps.example.test/a', false],
    'javascript map' => ['map_url', 'javascript:alert(1)', false],
    'ftp map' => ['map_url', 'ftp://maps.example.test/a', false],
    'not a link' => ['map_url', 'cerca del parque', false],
    'long map' => ['map_url', 'https://maps.example.test/'.str_repeat('a', 2030), false],
    'address at the limit' => ['address', str_repeat('a', 255), true],
    'address too long' => ['address', str_repeat('a', 256), false],
]);

it('CA-035-5: extra spaces are trimmed and an empty address or map is stored as null', function (): void {
    [$event, $admin] = placeActionScenario();

    $room = placeReload(app(CreateRoom::class)->handle($admin, $event, ['name' => 'Lugar', 'address' => '  Piso 2, ala norte  ', 'map_url' => '   ']));
    $blank = placeReload(app(CreateRoom::class)->handle($admin, $event, ['name' => 'Otro', 'address' => '   ']));

    expect($room->address)->toBe('Piso 2, ala norte')
        ->and($room->map_url)->toBeNull()
        ->and($blank->address)->toBeNull();
});

it('CA-035-6: the kind of a place with activities cannot change and the error says how many use it', function (): void {
    [$event, $admin] = placeActionScenario();
    $room = app(CreateRoom::class)->handle($admin, $event, ['name' => 'Sala A']);

    foreach ([1, 2] as $n) {
        CurrentOrganization::run($event->organization, fn () => EventActivity::factory()->scheduled('2027-03-1'.$n)->create(['event_id' => $event->id, 'room_id' => $room->id]));
    }

    try {
        app(UpdateRoom::class)->handle($admin, $room, ['name' => 'Sala A', 'kind' => 'external']);
        $this->fail('Debía rechazar el cambio de tipo de un lugar en uso.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('kind')
            ->and($exception->errors()['kind'][0])->toContain('2');
    }

    expect(placeReload($room)->kind)->toBe(RoomKind::Room);

    // Mandar el mismo tipo no es un cambio: se puede seguir editando el lugar en uso.
    app(UpdateRoom::class)->handle($admin, $room, ['name' => 'Sala A', 'kind' => 'room', 'capacity' => 80]);
    expect(placeReload($room)->capacity)->toBe(80);
});

it('CA-035-6: the kind of a place without activities does change', function (): void {
    [$event, $admin] = placeActionScenario();
    $room = app(CreateRoom::class)->handle($admin, $event, ['name' => 'Parque']);

    app(UpdateRoom::class)->handle($admin, $room, ['name' => 'Parque', 'kind' => 'external']);

    expect(placeReload($room)->kind)->toBe(RoomKind::External);
});

it('CA-035-6: a deleted activity no longer blocks the change of kind', function (): void {
    [$event, $admin] = placeActionScenario();
    $room = app(CreateRoom::class)->handle($admin, $event, ['name' => 'Sala A']);
    $activity = CurrentOrganization::run($event->organization, fn () => EventActivity::factory()->scheduled()->create(['event_id' => $event->id, 'room_id' => $room->id]));
    CurrentOrganization::run($event->organization, fn () => $activity->delete());

    app(UpdateRoom::class)->handle($admin, $room, ['name' => 'Sala A', 'kind' => 'external']);

    expect(placeReload($room)->kind)->toBe(RoomKind::External);
});

it('CA-035-9: the capacity is private by default, can be published and keeps its value when an edit does not mention it', function (): void {
    [$event, $admin] = placeActionScenario();
    $room = app(CreateRoom::class)->handle($admin, $event, ['name' => 'Auditorio', 'capacity' => 300]);

    expect(placeReload($room)->capacity_is_public)->toBeFalse();

    app(UpdateRoom::class)->handle($admin, $room, ['name' => 'Auditorio', 'capacity_is_public' => true]);
    $published = placeReload($room);

    app(UpdateRoom::class)->handle($admin, $room, ['name' => 'Auditorio II']);
    $renamed = placeReload($room);

    expect($published->capacity_is_public)->toBeTrue()
        ->and($renamed->capacity_is_public)->toBeTrue()
        ->and($renamed->capacity)->toBe(300)
        ->and($renamed->name)->toBe('Auditorio II');
});

it('CA-035-3: editing can clear the capacity, the address and the map with null', function (): void {
    [$event, $admin] = placeActionScenario();
    $room = app(CreateRoom::class)->handle($admin, $event, [
        'name' => 'Parque', 'kind' => 'external', 'capacity' => 50, 'address' => 'Entrada norte', 'map_url' => 'https://maps.example.test/p',
    ]);

    app(UpdateRoom::class)->handle($admin, $room, ['name' => 'Parque', 'capacity' => null, 'address' => null, 'map_url' => null]);
    $cleared = placeReload($room);

    expect($cleared->capacity)->toBeNull()
        ->and($cleared->address)->toBeNull()
        ->and($cleared->map_url)->toBeNull()
        ->and($cleared->kind)->toBe(RoomKind::External);
});

it('CA-035-7: a reviewer cannot create a place or change the kind and capacity of one', function (): void {
    [$event, $admin] = placeActionScenario();
    $reviewer = Memberships::attach(User::factory()->create(), $event->organization, OrganizationRole::Reviewer);
    $room = app(CreateRoom::class)->handle($admin, $event, ['name' => 'Sala A']);

    expect(fn () => app(CreateRoom::class)->handle($reviewer, $event, ['name' => 'Parque', 'kind' => 'external']))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateRoom::class)->handle($reviewer, $room, ['name' => 'Sala A', 'kind' => 'external', 'capacity' => 10]))
        ->toThrow(AuthorizationException::class);

    $after = placeReload($room);

    expect($after->kind)->toBe(RoomKind::Room)->and($after->capacity)->toBeNull();
});

it('CA-035-7: a user of another organization cannot change a place', function (): void {
    [$event, $admin] = placeActionScenario();
    [, $stranger] = placeActionScenario();
    $room = app(CreateRoom::class)->handle($admin, $event, ['name' => 'Sala A']);

    expect(fn () => app(UpdateRoom::class)->handle($stranger, $room, ['name' => 'Sala A', 'capacity' => 10]))
        ->toThrow(AuthorizationException::class);
});

it('CA-035-3: the audit records the fields that changed and the kind of a new place', function (): void {
    [$event, $admin] = placeActionScenario();
    $room = app(CreateRoom::class)->handle($admin, $event, ['name' => 'Parque', 'kind' => 'external']);
    app(UpdateRoom::class)->handle($admin, $room, ['name' => 'Parque', 'capacity' => 30, 'address' => 'Entrada norte']);

    $created = Activity::query()->where('event', 'room_created')->latest('id')->firstOrFail();
    $updated = Activity::query()->where('event', 'room_updated')->latest('id')->firstOrFail();

    expect($created->properties['kind'])->toBe('external')
        ->and($updated->properties['changed'])->toEqualCanonicalizing(['capacity', 'address']);
});
