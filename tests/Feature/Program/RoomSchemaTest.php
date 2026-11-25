<?php

declare(strict_types=1);

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
 * Plan 031, F2: las restricciones de la base para `rooms` y `event_activities.room_id`
 * (`docs/database-design.md` §8.4). Las reglas de negocio se prueban en `RoomActionsTest`.
 */

afterEach(function (): void {
    CurrentOrganization::forget();
});

function schemaRoom(Event $event, string $name = 'Auditorio', int $position = 1): Room
{
    return Room::factory()->create(['event_id' => $event->id, 'organization_id' => $event->organization_id, 'name' => $name, 'position' => $position]);
}

function schemaActivity(Event $event, ?Room $room = null, string $state = 'scheduled'): EventActivity
{
    return CurrentOrganization::run($event->organization, fn (): EventActivity => EventActivity::factory()->{$state}()->create([
        'event_id' => $event->id,
        'room_id' => $room?->id,
    ]));
}

it('CA-031-5: the database refuses a repeated room name in the same event without regard to case', function (): void {
    $event = Event::factory()->create();
    schemaRoom($event, 'Auditorio');

    expect(fn () => DB::transaction(fn () => schemaRoom($event, 'AUDITORIO', 2)))->toThrow(QueryException::class);
});

it('CA-031-5: the same room name is allowed in another event', function (): void {
    $first = Event::factory()->create();
    $second = Event::factory()->create(['organization_id' => $first->organization_id]);

    schemaRoom($first, 'Sala A');
    schemaRoom($second, 'Sala A');

    expect(DB::table('rooms')->count())->toBe(2);
});

it('CA-031-5: the composite foreign key refuses a room whose event belongs to another organization', function (): void {
    $event = Event::factory()->create();
    $other = Organization::factory()->create();

    expect(fn () => DB::transaction(fn () => DB::table('rooms')->insert([
        'id' => (string) Str::uuid(),
        'organization_id' => $other->id,
        'event_id' => $event->id,
        'name' => 'Cruzada',
        'position' => 1,
    ])))->toThrow(QueryException::class);
});

it('CA-031-5: the position must be at least 1', function (): void {
    $event = Event::factory()->create();

    expect(fn () => DB::transaction(fn () => schemaRoom($event, 'Cero', 0)))->toThrow(QueryException::class);
});

it('CA-031-9: an activity keeps a room of its own event', function (): void {
    $event = Event::factory()->create();
    $room = schemaRoom($event);

    $activity = schemaActivity($event, $room);

    expect($activity->refresh()->room_id)->toBe($room->id);
});

it('CA-031-9: the composite foreign key refuses a room of another event', function (): void {
    $event = Event::factory()->create();
    $other = Event::factory()->create(['organization_id' => $event->organization_id]);
    $foreignRoom = schemaRoom($other);

    expect(fn () => DB::transaction(fn () => schemaActivity($event, $foreignRoom)))->toThrow(QueryException::class);
});

it('CA-031-9: the database refuses a room on an activity without schedule', function (): void {
    $event = Event::factory()->create();
    $room = schemaRoom($event);

    expect(fn () => DB::transaction(fn () => schemaActivity($event, $room, 'unscheduled')))->toThrow(QueryException::class);
});

it('CA-031-7: the database refuses to delete a room that an activity uses', function (): void {
    $event = Event::factory()->create();
    $room = schemaRoom($event);
    schemaActivity($event, $room);

    expect(fn () => DB::transaction(fn () => DB::table('rooms')->where('id', $room->id)->delete()))->toThrow(QueryException::class);
});
