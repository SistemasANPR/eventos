<?php

declare(strict_types=1);

use App\Actions\Program\CreateRoom;
use App\Actions\Program\DeleteRoom;
use App\Actions\Program\MoveRoom;
use App\Actions\Program\UpdateRoom;
use App\Enums\OrganizationRole;
use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Room;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
});

/** @return array{0: Event, 1: User} evento de una organización nueva y un usuario con el rol indicado en ella */
function roomScenario(OrganizationRole $role = OrganizationRole::OrgAdmin): array
{
    $organization = Memberships::organization();
    $user = Memberships::attach(User::factory()->create(), $organization, $role);

    return [Event::factory()->create(['organization_id' => $organization->id]), $user];
}

/** @return list<string> nombres de las salas del evento, en orden de posición */
function roomNames(Event $event): array
{
    return CurrentOrganization::run(
        $event->organization,
        fn (): array => Room::query()->where('event_id', $event->id)->orderBy('position')->pluck('name')->all(),
    );
}

/** @return list<int> posiciones de las salas del evento, en orden */
function roomPositions(Event $event): array
{
    return CurrentOrganization::run(
        $event->organization,
        fn (): array => Room::query()->where('event_id', $event->id)->orderBy('position')->pluck('position')->all(),
    );
}

function roomActivity(Event $event, Room $room, bool $trashed = false): EventActivity
{
    $activity = CurrentOrganization::run($event->organization, fn (): EventActivity => EventActivity::factory()->scheduled()->create([
        'event_id' => $event->id,
        'room_id' => $room->id,
    ]));

    if ($trashed) {
        CurrentOrganization::run($event->organization, fn () => $activity->delete());
    }

    return $activity;
}

it('CA-031-5: creates rooms at the end of the event list with the name trimmed', function (): void {
    [$event, $admin] = roomScenario();
    $create = app(CreateRoom::class);

    $first = $create->handle($admin, $event, ['name' => '  Auditorio ']);
    $second = $create->handle($admin, $event, ['name' => 'Sala B']);

    expect($first)->toBeInstanceOf(Room::class)
        ->and($first->name)->toBe('Auditorio')
        ->and([$first->position, $second->position])->toBe([1, 2])
        ->and(roomNames($event))->toBe(['Auditorio', 'Sala B']);
});

it('CA-031-5: the name is required, has at most 100 characters and is unique in the event without regard to case', function (): void {
    [$event, $admin] = roomScenario();
    $create = app(CreateRoom::class);
    $create->handle($admin, $event, ['name' => 'Auditorio']);

    foreach ([[], ['name' => ''], ['name' => '   '], ['name' => str_repeat('a', 101)], ['name' => ['x']], ['name' => 'AUDITORIO'], ['name' => '  auditorio  ']] as $data) {
        expect(fn () => $create->handle($admin, $event, $data))->toThrow(ValidationException::class);
    }

    expect($create->handle($admin, $event, ['name' => str_repeat('a', 100)])->name)->toHaveLength(100)
        ->and(roomNames($event))->toHaveCount(2);
});

it('CA-031-5: the same name is valid in another event of the organization', function (): void {
    [$event, $admin] = roomScenario();
    $other = Event::factory()->create(['organization_id' => $event->organization_id]);

    app(CreateRoom::class)->handle($admin, $event, ['name' => 'Sala A']);
    app(CreateRoom::class)->handle($admin, $other, ['name' => 'Sala A']);

    expect(roomNames($event))->toBe(['Sala A'])
        ->and(roomNames($other))->toBe(['Sala A']);
});

it('CA-031-5: renaming keeps the uniqueness rule and a room does not clash with itself', function (): void {
    [$event, $admin] = roomScenario();
    $first = app(CreateRoom::class)->handle($admin, $event, ['name' => 'Auditorio']);
    app(CreateRoom::class)->handle($admin, $event, ['name' => 'Sala B']);
    $update = app(UpdateRoom::class);

    expect(fn () => $update->handle($admin, $first, ['name' => 'sala b']))->toThrow(ValidationException::class)
        ->and($update->handle($admin, $first, ['name' => 'AUDITORIO'])->name)->toBe('AUDITORIO')
        ->and($update->handle($admin, $first, ['name' => 'Principal'])->name)->toBe('Principal')
        ->and(roomNames($event))->toBe(['Principal', 'Sala B']);
});

it('CA-031-7: a room without activities is deleted', function (): void {
    [$event, $admin] = roomScenario();
    $first = app(CreateRoom::class)->handle($admin, $event, ['name' => 'Auditorio']);
    app(CreateRoom::class)->handle($admin, $event, ['name' => 'Sala B']);

    app(DeleteRoom::class)->handle($admin, $first);

    expect(roomNames($event))->toBe(['Sala B']);
});

it('CA-031-7: a room with activities is not deleted, the error says how many and the activities keep it', function (): void {
    [$event, $admin] = roomScenario();
    $room = app(CreateRoom::class)->handle($admin, $event, ['name' => 'Auditorio']);
    $one = roomActivity($event, $room);
    $two = roomActivity($event, $room);

    try {
        app(DeleteRoom::class)->handle($admin, $room);
        $this->fail('Debió rechazar la eliminación.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('room')
            ->and($exception->errors()['room'][0])->toContain('2');
    }

    expect(roomNames($event))->toBe(['Auditorio'])
        ->and($one->refresh()->room_id)->toBe($room->id)
        ->and($two->refresh()->room_id)->toBe($room->id);
});

it('CA-031-7: an eliminated activity does not hold the room and loses its link when the room is deleted', function (): void {
    [$event, $admin] = roomScenario();
    $room = app(CreateRoom::class)->handle($admin, $event, ['name' => 'Auditorio']);
    $gone = roomActivity($event, $room, trashed: true);

    app(DeleteRoom::class)->handle($admin, $room);

    $row = CurrentOrganization::run($event->organization, fn () => EventActivity::withTrashed()->findOrFail($gone->id));

    expect(roomNames($event))->toBe([])
        ->and($row->room_id)->toBeNull();
});

it('CA-031-8: moving a room up or down swaps it with its neighbor and keeps the positions consecutive', function (): void {
    [$event, $admin] = roomScenario();
    $create = app(CreateRoom::class);
    $a = $create->handle($admin, $event, ['name' => 'A']);
    $create->handle($admin, $event, ['name' => 'B']);
    $c = $create->handle($admin, $event, ['name' => 'C']);
    $move = app(MoveRoom::class);

    $move->handle($admin, $c, MoveRoom::UP);
    expect(roomNames($event))->toBe(['A', 'C', 'B']);

    $move->handle($admin, $a, MoveRoom::DOWN);
    expect(roomNames($event))->toBe(['C', 'A', 'B'])
        ->and(roomPositions($event))->toBe([1, 2, 3]);
});

it('CA-031-8: moving the first room up or the last room down changes nothing', function (): void {
    [$event, $admin] = roomScenario();
    $create = app(CreateRoom::class);
    $a = $create->handle($admin, $event, ['name' => 'A']);
    $b = $create->handle($admin, $event, ['name' => 'B']);

    app(MoveRoom::class)->handle($admin, $a, MoveRoom::UP);
    app(MoveRoom::class)->handle($admin, $b, MoveRoom::DOWN);

    expect(roomNames($event))->toBe(['A', 'B'])
        ->and(roomPositions($event))->toBe([1, 2])
        ->and(fn () => app(MoveRoom::class)->handle($admin, $a, 'sideways'))->toThrow(InvalidArgumentException::class);
});

it('CA-031-8: positions are renumbered after a deletion leaves a gap', function (): void {
    [$event, $admin] = roomScenario();
    $create = app(CreateRoom::class);
    $create->handle($admin, $event, ['name' => 'A']);
    $b = $create->handle($admin, $event, ['name' => 'B']);
    $c = $create->handle($admin, $event, ['name' => 'C']);
    app(DeleteRoom::class)->handle($admin, $b);

    app(MoveRoom::class)->handle($admin, $c, MoveRoom::UP);

    expect(roomNames($event))->toBe(['C', 'A'])
        ->and(roomPositions($event))->toBe([1, 2]);
});

it('CA-031-8: creating, renaming, moving and deleting a room is audited with who and the values', function (): void {
    [$event, $admin] = roomScenario();
    $room = app(CreateRoom::class)->handle($admin, $event, ['name' => 'Auditorio']);
    app(CreateRoom::class)->handle($admin, $event, ['name' => 'Sala B']);
    app(UpdateRoom::class)->handle($admin, $room, ['name' => 'Principal']);
    app(MoveRoom::class)->handle($admin, $room, MoveRoom::DOWN);
    app(DeleteRoom::class)->handle($admin, $room);

    $entries = Activity::query()->where('event', 'like', 'room_%')->get();

    expect($entries->pluck('event')->unique()->sort()->values()->all())->toBe(['room_created', 'room_deleted', 'room_moved', 'room_updated'])
        ->and($entries->every(fn (Activity $entry): bool => $entry->causer_id === $admin->id))->toBeTrue()
        ->and($entries->firstWhere('event', 'room_updated')?->properties->toArray())
        ->toMatchArray(['old' => ['name' => 'Auditorio'], 'new' => ['name' => 'Principal']])
        ->and($entries->firstWhere('event', 'room_moved')?->properties->toArray())
        ->toMatchArray(['old' => ['position' => 1], 'new' => ['position' => 2]]);
});

it('CA-031-6: a reviewer cannot create, rename, move or delete rooms', function (): void {
    [$event, $reviewer] = roomScenario(OrganizationRole::Reviewer);
    $admin = Memberships::attach(User::factory()->create(), $event->organization, OrganizationRole::OrgAdmin);
    $room = app(CreateRoom::class)->handle($admin, $event, ['name' => 'Auditorio']);

    expect(fn () => app(CreateRoom::class)->handle($reviewer, $event, ['name' => 'X']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateRoom::class)->handle($reviewer, $room, ['name' => 'X']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(MoveRoom::class)->handle($reviewer, $room, MoveRoom::DOWN))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteRoom::class)->handle($reviewer, $room))->toThrow(AuthorizationException::class)
        ->and(roomNames($event))->toBe(['Auditorio']);
});

it('CA-031-6: an organizer creates, renames and moves but cannot delete', function (): void {
    [$event, $organizer] = roomScenario(OrganizationRole::Organizer);
    $create = app(CreateRoom::class);
    $room = $create->handle($organizer, $event, ['name' => 'Auditorio']);
    $create->handle($organizer, $event, ['name' => 'Sala B']);

    app(UpdateRoom::class)->handle($organizer, $room, ['name' => 'Principal']);
    app(MoveRoom::class)->handle($organizer, $room, MoveRoom::DOWN);

    expect(fn () => app(DeleteRoom::class)->handle($organizer, $room))->toThrow(AuthorizationException::class)
        ->and(roomNames($event))->toBe(['Sala B', 'Principal']);
});

it('CA-031-6: an admin of another organization cannot touch the rooms of this one', function (): void {
    [$event, $admin] = roomScenario();
    [, $outsider] = roomScenario();
    $room = app(CreateRoom::class)->handle($admin, $event, ['name' => 'Auditorio']);
    $room = Room::query()->withoutGlobalScopes()->findOrFail($room->id);

    expect(fn () => app(CreateRoom::class)->handle($outsider, $event, ['name' => 'X']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateRoom::class)->handle($outsider, $room, ['name' => 'X']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(MoveRoom::class)->handle($outsider, $room, MoveRoom::DOWN))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteRoom::class)->handle($outsider, $room))->toThrow(AuthorizationException::class)
        ->and(roomNames($event))->toBe(['Auditorio']);
});

it('CA-031-6: within the context of one organization the rooms of another are not found', function (): void {
    [$eventA] = roomScenario();
    [$eventB, $adminB] = roomScenario();
    $room = app(CreateRoom::class)->handle($adminB, $eventB, ['name' => 'Ajena']);

    CurrentOrganization::set($eventA->organization);

    expect(fn () => Room::query()->findOrFail($room->id))->toThrow(ModelNotFoundException::class)
        ->and(Room::query()->count())->toBe(0);
});
