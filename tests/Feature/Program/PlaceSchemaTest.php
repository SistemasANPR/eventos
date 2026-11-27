<?php

declare(strict_types=1);

use App\Enums\RoomKind;
use App\Models\ActivityContact;
use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Organization;
use App\Models\Room;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * Plan 035, F1: las restricciones de la base para los lugares (`rooms.kind`, capacidad, dirección y mapa),
 * el cupo y los datos externos de la actividad, y los responsables (`activity_contacts`)
 * (`docs/database-design.md` §8.5). Las reglas de negocio se prueban en las pruebas de las Actions.
 */

afterEach(function (): void {
    CurrentOrganization::forget();
});

function placeEvent(?Organization $organization = null): Event
{
    return Event::factory()->create($organization === null ? [] : ['organization_id' => $organization->id]);
}

/** @param  array<string, mixed>  $attributes */
function placeRow(Event $event, array $attributes = []): string
{
    $id = (string) Str::uuid();

    DB::table('rooms')->insert([
        'id' => $id,
        'organization_id' => $event->organization_id,
        'event_id' => $event->id,
        'name' => 'Lugar '.Str::random(6),
        'position' => 1,
        'created_at' => now(),
        'updated_at' => now(),
        ...$attributes,
    ]);

    return $id;
}

function placeActivity(Event $event): EventActivity
{
    return CurrentOrganization::run($event->organization, fn (): EventActivity => EventActivity::factory()->scheduled()->create(['event_id' => $event->id]));
}

/**
 * @param  array<string, mixed>  $attributes
 * @return array<string, mixed>
 */
function contactRow(EventActivity $activity, array $attributes = []): array
{
    return [
        'id' => (string) Str::uuid(),
        'organization_id' => $activity->organization_id,
        'event_id' => $activity->event_id,
        'activity_id' => $activity->id,
        'name' => 'Responsable de prueba',
        'position' => 1,
        'created_at' => now(),
        'updated_at' => now(),
        ...$attributes,
    ];
}

it('CA-035-1: a room created the old way is a room without capacity, address or map, and an activity has no quota or external data', function (): void {
    $event = placeEvent();
    $roomId = placeRow($event);
    $activity = placeActivity($event);

    $room = DB::table('rooms')->where('id', $roomId)->first();
    $row = DB::table('event_activities')->where('id', $activity->id)->first();

    expect($room->kind)->toBe('room')
        ->and($room->capacity)->toBeNull()
        ->and($room->capacity_is_public)->toBeFalse()
        ->and($room->address)->toBeNull()
        ->and($room->map_url)->toBeNull()
        ->and($row->quota)->toBeNull()
        ->and($row->meeting_point)->toBeNull()
        ->and($row->transport_info)->toBeNull();
});

it('CA-035-1: the room model reads its kind as the enum and defaults to a room', function (): void {
    $event = placeEvent();
    $room = Room::factory()->create(['event_id' => $event->id, 'organization_id' => $event->organization_id])->fresh();

    expect($room->kind)->toBe(RoomKind::Room)
        ->and($room->capacity_is_public)->toBeFalse();
});

it('CA-035-2: the database refuses a room kind other than room or external', function (): void {
    $event = placeEvent();

    expect(fn () => DB::transaction(fn () => placeRow($event, ['kind' => 'parque'])))->toThrow(QueryException::class);

    placeRow($event, ['kind' => 'external', 'name' => 'Parque']);
    expect(DB::table('rooms')->where('kind', 'external')->count())->toBe(1);
});

it('CA-035-2: the database refuses a capacity or a quota below one and accepts a missing one', function (): void {
    $event = placeEvent();
    $activity = placeActivity($event);

    foreach ([0, -5] as $invalid) {
        expect(fn () => DB::transaction(fn () => placeRow($event, ['capacity' => $invalid])))->toThrow(QueryException::class)
            ->and(fn () => DB::transaction(fn () => DB::table('event_activities')->where('id', $activity->id)->update(['quota' => $invalid])))->toThrow(QueryException::class);
    }

    placeRow($event, ['capacity' => 25, 'name' => 'Con capacidad']);
    DB::table('event_activities')->where('id', $activity->id)->update(['quota' => 30]);
    DB::table('event_activities')->where('id', $activity->id)->update(['quota' => null]);

    expect(DB::table('rooms')->where('capacity', 25)->count())->toBe(1)
        ->and(DB::table('event_activities')->where('id', $activity->id)->value('quota'))->toBeNull();
});

it('CA-035-2: a contact of an activity keeps the event and organization of that activity', function (): void {
    $event = placeEvent();
    $otherEvent = placeEvent($event->organization);
    $foreign = placeEvent();
    $activity = placeActivity($event);

    DB::table('activity_contacts')->insert(contactRow($activity));
    expect(DB::table('activity_contacts')->count())->toBe(1);

    expect(fn () => DB::transaction(fn () => DB::table('activity_contacts')->insert(contactRow($activity, ['event_id' => $otherEvent->id, 'position' => 2]))))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('activity_contacts')->insert(contactRow($activity, ['organization_id' => $foreign->organization_id, 'event_id' => $foreign->id, 'position' => 3]))))
        ->toThrow(QueryException::class);
});

it('CA-035-2: contacts have a unique position per activity and a position of at least one', function (): void {
    $event = placeEvent();
    $activity = placeActivity($event);

    DB::table('activity_contacts')->insert(contactRow($activity));

    expect(fn () => DB::transaction(fn () => DB::table('activity_contacts')->insert(contactRow($activity))))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('activity_contacts')->insert(contactRow($activity, ['position' => 0]))))->toThrow(QueryException::class);
});

it('CA-035-2: deleting an activity for good deletes its contacts', function (): void {
    $event = placeEvent();
    $activity = placeActivity($event);
    DB::table('activity_contacts')->insert(contactRow($activity));
    DB::table('activity_contacts')->insert(contactRow($activity, ['position' => 2, 'name' => 'Segundo']));

    DB::table('event_activities')->where('id', $activity->id)->delete();

    expect(DB::table('activity_contacts')->count())->toBe(0);
});

it('CA-035-2: the organization of a contact cannot change', function (): void {
    $event = placeEvent();
    $activity = placeActivity($event);
    $row = contactRow($activity);
    DB::table('activity_contacts')->insert($row);
    $other = Organization::factory()->create();

    expect(fn () => DB::transaction(fn () => DB::table('activity_contacts')->where('id', $row['id'])->update(['organization_id' => $other->id])))
        ->toThrow(QueryException::class);
});

it('CA-035-23: a contact can be created for an explicit organization and starts private', function (): void {
    $organization = Organization::factory()->create();

    $contact = ActivityContact::factory()->create(['organization_id' => $organization->id]);

    expect($contact->organization_id)->toBe($organization->id)
        ->and($contact->fresh()->is_public)->toBeFalse()
        ->and($contact->fresh()->position)->toBe(1);
});
