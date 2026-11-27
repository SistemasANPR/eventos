<?php

declare(strict_types=1);

use App\Actions\Program\CreateActivity;
use App\Actions\Program\FindPublicActivity;
use App\Actions\Program\SyncActivitySpeakers;
use App\Actions\Program\UpdateActivity;
use App\Enums\OrganizationRole;
use App\Exceptions\Domain\ActivityRoomConflict;
use App\Models\ActivityCategory;
use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Room;
use App\Models\Speaker;
use App\Models\User;
use App\Support\Program\PublicActivityData;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\Memberships;

require_once __DIR__.'/../../Support/public-program-helpers.php';

uses(RefreshDatabase::class);

/*
 * Plan 031, F3: sala en la actividad y reglas de choque (CA-031-9 a 18 y 28). Fecha y hora son locales
 * del evento; los intervalos son medio abiertos (DA-5): terminar a las 10:00 y empezar a las 10:00 no choca.
 */

afterEach(function (): void {
    CurrentOrganization::forget();
});

/** @return array{0: Event, 1: User} evento de una organización nueva y su administrador */
function roomConflictScenario(): array
{
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);

    return [Event::factory()->create(['organization_id' => $organization->id]), $admin];
}

function rcRoom(Event $event, string $name = 'Sala A'): Room
{
    return CurrentOrganization::run($event->organization, fn (): Room => Room::factory()->create(['event_id' => $event->id, 'name' => $name]));
}

/** @param  array<string, mixed>  $attributes */
function rcActivity(Event $event, ?Room $room, string $from, string $to, string $title = 'Existente', array $attributes = [], string $day = '2027-03-10', ?string $endDay = null): EventActivity
{
    return CurrentOrganization::run($event->organization, fn (): EventActivity => EventActivity::factory()->create([
        'event_id' => $event->id,
        'room_id' => $room?->id,
        'title' => $title,
        'starts_on' => $day,
        'ends_on' => $endDay ?? $day,
        'starts_at' => $from,
        'ends_at' => $to,
        ...$attributes,
    ]));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function rcData(array $overrides = []): array
{
    return array_replace([
        'title' => 'Nueva',
        'starts_on' => '2027-03-10',
        'ends_on' => '2027-03-10',
        'starts_at' => '10:00:00',
        'ends_at' => '11:00:00',
    ], $overrides);
}

function rcSpeaker(Event $event, string $first = 'Ada'): Speaker
{
    return CurrentOrganization::run($event->organization, fn (): Speaker => Speaker::factory()->create(['event_id' => $event->id, 'first_name' => $first, 'last_name' => 'Lovelace']));
}

function rcAssign(EventActivity $activity, Speaker $speaker): void
{
    DB::table('activity_speaker')->insert([
        'activity_id' => $activity->id, 'speaker_id' => $speaker->id,
        'organization_id' => $activity->organization_id, 'event_id' => $activity->event_id, 'position' => 0,
    ]);
}

/** @return array<string, mixed> el estado de la actividad tal como está en la base, para compararlo antes y después */
function rcSnapshot(EventActivity $activity): array
{
    return [
        'row' => DB::table('event_activities')->where('id', $activity->id)->first(['title', 'room_id', 'starts_on', 'ends_on', 'starts_at', 'ends_at']),
        'speakers' => DB::table('activity_speaker')->where('activity_id', $activity->id)->pluck('speaker_id')->all(),
        'categories' => DB::table('activity_activity_category')->where('activity_id', $activity->id)->pluck('activity_category_id')->all(),
    ];
}

it('CA-031-9: creating an activity with a room of its event saves it and the room is optional', function (): void {
    [$event, $admin] = roomConflictScenario();
    $room = rcRoom($event);

    $withRoom = app(CreateActivity::class)->handle($admin, $event, rcData(['room_id' => $room->id]));
    $withoutRoom = app(CreateActivity::class)->handle($admin, $event, rcData(['title' => 'Sin sala']));

    expect($withRoom->fresh()->room_id)->toBe($room->id)
        ->and($withoutRoom->fresh()->room_id)->toBeNull();
});

it('CA-031-9: a room of another event or another organization is refused with a validation error', function (): void {
    [$event, $admin] = roomConflictScenario();
    $otherEvent = Event::factory()->create(['organization_id' => $event->organization_id]);
    $foreign = Event::factory()->create();

    foreach ([rcRoom($otherEvent, 'De otro evento'), rcRoom($foreign, 'De otra organizacion')] as $room) {
        expect(fn () => app(CreateActivity::class)->handle($admin, $event, rcData(['room_id' => $room->id])))
            ->toThrow(ValidationException::class);
    }

    expect(fn () => app(CreateActivity::class)->handle($admin, $event, rcData(['room_id' => 'no-es-un-uuid'])))
        ->toThrow(ValidationException::class);

    expect(CurrentOrganization::run($event->organization, fn () => EventActivity::query()->where('event_id', $event->id)->count()))->toBe(0);
});

it('CA-031-9: editing keeps the room when the data does not mention it, changes it when it does and clears it with null', function (): void {
    [$event, $admin] = roomConflictScenario();
    [$first, $second] = [rcRoom($event, 'Sala A'), rcRoom($event, 'Sala B')];
    $activity = rcActivity($event, $first, '10:00:00', '11:00:00');

    app(UpdateActivity::class)->handle($admin, $activity, rcData(['title' => 'Editada']));
    expect($activity->fresh()->room_id)->toBe($first->id);

    app(UpdateActivity::class)->handle($admin, $activity, rcData(['title' => 'Editada', 'room_id' => $second->id]));
    expect($activity->fresh()->room_id)->toBe($second->id);

    app(UpdateActivity::class)->handle($admin, $activity, rcData(['title' => 'Editada', 'room_id' => null]));
    expect($activity->fresh()->room_id)->toBeNull();
});

it('CA-031-10: an overlapping activity in the same room is refused with ACTIVITY_ROOM_CONFLICT naming the other activity', function (): void {
    [$event, $admin] = roomConflictScenario();
    $room = rcRoom($event);
    rcActivity($event, $room, '10:00:00', '11:00:00', 'Charla de apertura');

    try {
        app(CreateActivity::class)->handle($admin, $event, rcData(['room_id' => $room->id, 'starts_at' => '10:30:00', 'ends_at' => '11:30:00']));
        $this->fail('Debía rechazar el choque de sala al crear.');
    } catch (ActivityRoomConflict $exception) {
        expect($exception->getMessage())->toContain('Charla de apertura')->toContain('ACTIVITY_ROOM_CONFLICT');
    }

    expect(CurrentOrganization::run($event->organization, fn () => EventActivity::query()->where('event_id', $event->id)->count()))->toBe(1);
});

it('CA-031-10: moving an existing activity onto an occupied slot is refused and nothing is written', function (): void {
    [$event, $admin] = roomConflictScenario();
    $room = rcRoom($event);
    rcActivity($event, $room, '10:00:00', '11:00:00', 'Ocupa la sala');
    $moving = rcActivity($event, $room, '14:00:00', '15:00:00', 'La que se mueve');
    $before = rcSnapshot($moving);

    expect(fn () => app(UpdateActivity::class)->handle($admin, $moving, rcData(['title' => 'La que se mueve', 'starts_at' => '10:15:00', 'ends_at' => '10:45:00'])))
        ->toThrow(ActivityRoomConflict::class);

    expect(rcSnapshot($moving))->toEqual($before);
});

it('CA-031-10: changing only the time of an activity that keeps its room is also checked against that room', function (): void {
    [$event, $admin] = roomConflictScenario();
    $room = rcRoom($event);
    rcActivity($event, $room, '10:00:00', '11:00:00');
    $moving = rcActivity($event, $room, '12:00:00', '13:00:00', 'Se queda en su sala');

    expect(fn () => app(UpdateActivity::class)->handle($admin, $moving, rcData(['title' => 'Se queda en su sala', 'starts_at' => '10:30:00', 'ends_at' => '11:30:00'])))
        ->toThrow(ActivityRoomConflict::class);
});

it('CA-031-10: an activity never collides with itself', function (): void {
    [$event, $admin] = roomConflictScenario();
    $room = rcRoom($event);
    $activity = rcActivity($event, $room, '10:00:00', '11:00:00');

    app(UpdateActivity::class)->handle($admin, $activity, rcData(['title' => 'Mismo horario', 'starts_at' => '10:00:00', 'ends_at' => '11:30:00']));

    expect($activity->fresh()->title)->toBe('Mismo horario');
});

it('CA-031-11: ending at 10:00 and starting at 10:00 in the same room does not collide', function (): void {
    [$event, $admin] = roomConflictScenario();
    $room = rcRoom($event);
    rcActivity($event, $room, '09:00:00', '10:00:00');

    $created = app(CreateActivity::class)->handle($admin, $event, rcData(['room_id' => $room->id, 'starts_at' => '10:00:00', 'ends_at' => '11:00:00']));
    $before = app(CreateActivity::class)->handle($admin, $event, rcData(['title' => 'Antes', 'room_id' => $room->id, 'starts_at' => '08:00:00', 'ends_at' => '09:00:00']));

    expect($created->fresh()->room_id)->toBe($room->id)->and($before->fresh()->room_id)->toBe($room->id);
});

it('CA-031-12: an activity crossing midnight blocks the early hours of the next day in its room', function (): void {
    [$event, $admin] = roomConflictScenario();
    $room = rcRoom($event);
    rcActivity($event, $room, '22:00:00', '02:00:00', 'Fiesta', day: '2027-03-10', endDay: '2027-03-11');

    expect(fn () => app(CreateActivity::class)->handle($admin, $event, rcData([
        'room_id' => $room->id, 'starts_on' => '2027-03-11', 'ends_on' => '2027-03-11', 'starts_at' => '01:00:00', 'ends_at' => '02:30:00',
    ])))->toThrow(ActivityRoomConflict::class);

    $fits = app(CreateActivity::class)->handle($admin, $event, rcData([
        'title' => 'Desayuno', 'room_id' => $room->id, 'starts_on' => '2027-03-11', 'ends_on' => '2027-03-11', 'starts_at' => '02:00:00', 'ends_at' => '03:00:00',
    ]));

    expect($fits->fresh()->room_id)->toBe($room->id);
});

it('CA-031-13: the same time in another room, or without room, is accepted', function (): void {
    [$event, $admin] = roomConflictScenario();
    [$one, $two] = [rcRoom($event, 'Sala A'), rcRoom($event, 'Sala B')];
    rcActivity($event, $one, '10:00:00', '11:00:00');
    rcActivity($event, null, '10:00:00', '11:00:00', 'Sin sala existente');

    $otherRoom = app(CreateActivity::class)->handle($admin, $event, rcData(['title' => 'En B', 'room_id' => $two->id]));
    $noRoom = app(CreateActivity::class)->handle($admin, $event, rcData(['title' => 'Sin sala nueva']));

    expect($otherRoom->fresh()->room_id)->toBe($two->id)->and($noRoom->fresh()->room_id)->toBeNull();
});

it('CA-031-14: an unpublished or hidden activity still holds its room, a deleted one does not', function (): void {
    [$event, $admin] = roomConflictScenario();
    $room = rcRoom($event);
    $unpublished = rcActivity($event, $room, '10:00:00', '11:00:00', 'No publicada', ['is_published' => false]);
    rcActivity($event, $room, '12:00:00', '13:00:00', 'Oculta', ['is_published' => false, 'hidden_by_reversal_at' => now()]);
    $deleted = rcActivity($event, $room, '15:00:00', '16:00:00', 'Eliminada');
    CurrentOrganization::run($event->organization, fn () => $deleted->delete());

    foreach (['10:30:00', '12:30:00'] as $start) {
        expect(fn () => app(CreateActivity::class)->handle($admin, $event, rcData(['room_id' => $room->id, 'starts_at' => $start, 'ends_at' => '13:30:00'])))
            ->toThrow(ActivityRoomConflict::class);
    }

    $free = app(CreateActivity::class)->handle($admin, $event, rcData(['title' => 'Sobre la eliminada', 'room_id' => $room->id, 'starts_at' => '15:00:00', 'ends_at' => '16:00:00']));

    expect($free->fresh()->room_id)->toBe($room->id)->and($unpublished->fresh())->not->toBeNull();
});

it('CA-031-14: a room occupied in another event or organization does not matter', function (): void {
    [$event, $admin] = roomConflictScenario();
    $room = rcRoom($event);
    $otherEvent = Event::factory()->create(['organization_id' => $event->organization_id]);
    rcActivity($otherEvent, rcRoom($otherEvent, 'Sala A'), '10:00:00', '11:00:00');

    $created = app(CreateActivity::class)->handle($admin, $event, rcData(['room_id' => $room->id]));

    expect($created->fresh()->room_id)->toBe($room->id);
});

it('CA-031-15: a speaker in two overlapping activities is saved with a warning naming both', function (): void {
    [$event, $admin] = roomConflictScenario();
    $speaker = rcSpeaker($event, 'Ada');
    $other = rcActivity($event, rcRoom($event, 'Sala A'), '10:00:00', '11:00:00', 'Primera charla');
    rcAssign($other, $speaker);

    $created = app(CreateActivity::class)->handle($admin, $event, rcData([
        'title' => 'Segunda charla', 'room_id' => rcRoom($event, 'Sala B')->id, 'starts_at' => '10:30:00', 'ends_at' => '11:30:00', 'speaker_ids' => [$speaker->id],
    ]));

    expect($created->fresh())->not->toBeNull()
        ->and($created->scheduleWarnings)->toHaveCount(1)
        ->and($created->scheduleWarnings[0]['speaker'])->toBe('Ada Lovelace')
        ->and($created->scheduleWarnings[0]['activity'])->toBe('Primera charla');
});

it('CA-031-15: assigning the speaker afterwards, and editing the time, also return the warning; touching times do not', function (): void {
    [$event, $admin] = roomConflictScenario();
    $speaker = rcSpeaker($event);
    $first = rcActivity($event, null, '10:00:00', '11:00:00', 'Primera');
    $second = rcActivity($event, null, '10:30:00', '11:30:00', 'Segunda');
    $third = rcActivity($event, null, '11:00:00', '12:00:00', 'Tercera');
    rcAssign($first, $speaker);

    $synced = app(SyncActivitySpeakers::class)->handle($admin, $second, [$speaker->id]);
    expect($synced->scheduleWarnings)->toHaveCount(1)->and($synced->scheduleWarnings[0]['activity'])->toBe('Primera');

    // La tercera empieza cuando termina la primera: no se cruzan, y con la segunda sí.
    $clean = app(SyncActivitySpeakers::class)->handle($admin, $third, [$speaker->id]);
    expect($clean->scheduleWarnings)->toHaveCount(1)->and($clean->scheduleWarnings[0]['activity'])->toBe('Segunda');

    $edited = app(UpdateActivity::class)->handle($admin, $third, rcData(['title' => 'Tercera', 'starts_at' => '13:00:00', 'ends_at' => '14:00:00']));
    expect($edited->scheduleWarnings)->toBe([]);
});

it('CA-031-15: a speaker clash is a warning only: the activity of another event or without schedule never counts', function (): void {
    [$event, $admin] = roomConflictScenario();
    $speaker = rcSpeaker($event);
    $unscheduled = CurrentOrganization::run($event->organization, fn (): EventActivity => EventActivity::factory()->unscheduled()->create(['event_id' => $event->id]));
    rcAssign($unscheduled, $speaker);

    $created = app(CreateActivity::class)->handle($admin, $event, rcData(['speaker_ids' => [$speaker->id]]));

    expect($created->scheduleWarnings)->toBe([]);
});

it('CA-031-16: a room conflict in a multi-step change rolls everything back', function (): void {
    [$event, $admin] = roomConflictScenario();
    $room = rcRoom($event);
    $speaker = rcSpeaker($event);
    $category = CurrentOrganization::run($event->organization, fn (): ActivityCategory => ActivityCategory::factory()->create(['event_id' => $event->id]));
    rcActivity($event, $room, '10:00:00', '11:00:00');
    $moving = rcActivity($event, null, '14:00:00', '15:00:00', 'Original');
    $before = rcSnapshot($moving);

    expect(fn () => app(UpdateActivity::class)->handle($admin, $moving, rcData([
        'title' => 'Cambiada', 'room_id' => $room->id, 'starts_at' => '10:15:00', 'ends_at' => '10:45:00',
        'category_ids' => [$category->id], 'speaker_ids' => [$speaker->id],
    ])))->toThrow(ActivityRoomConflict::class);

    expect(rcSnapshot($moving))->toEqual($before);
});

it('CA-031-17: the check and the write run in one transaction with the event row locked first', function (): void {
    [$event, $admin] = roomConflictScenario();
    $room = rcRoom($event);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    app(CreateActivity::class)->handle($admin, $event, rcData(['room_id' => $room->id]));

    $lock = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'from "events"') && str_contains($sql, 'for update'));
    $check = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'from "event_activities"') && str_contains($sql, 'room_id'));
    $write = collect($queries)->search(fn (string $sql): bool => str_starts_with($sql, 'insert into "event_activities"'));

    expect($lock)->not->toBeFalse()->and($check)->not->toBeFalse()->and($write)->not->toBeFalse()
        ->and($lock)->toBeLessThan($check)
        ->and($check)->toBeLessThan($write);
});

it('CA-031-18: the existing calls without room keep working with the same arguments', function (): void {
    [$event, $admin] = roomConflictScenario();

    $created = app(CreateActivity::class)->handle($admin, $event, rcData());
    $updated = app(UpdateActivity::class)->handle($admin, $created, rcData(['title' => 'Otro nombre']));
    $synced = app(SyncActivitySpeakers::class)->handle($admin, $updated, []);

    expect($synced->fresh()->title)->toBe('Otro nombre')->and($synced->fresh()->room_id)->toBeNull();
});

it('CA-031-28: the public summary of an activity with a room never mentions the room and the detail names it without its id', function (): void {
    $event = ppEvent();
    $room = rcRoom($event, 'Sala secreta del sotano');
    $activity = rcActivity($event, $room, '10:00:00', '11:00:00', 'Con sala');

    $found = ppIn($event, fn () => app(FindPublicActivity::class)->handle($event, $activity->id));
    [$detail, $summary] = ppIn($event, fn (): array => [PublicActivityData::detail($found, $event), PublicActivityData::summary($found, $event)]);
    // Plan 035 (DA-13) cambia a propósito el contrato público: `limitedSeats` en el resumen y cupo, lugar y demás en el detalle.
    $keys = ['id', 'title', 'summary', 'date', 'endDate', 'startTime', 'endTime', 'categories', 'speakers', 'iconUrl', 'limitedSeats', 'url'];

    expect(array_keys($summary))->toBe($keys)
        ->and(array_keys($detail))->toBe([...$keys, 'descriptionHtml', 'imageUrl', 'seats', 'place', 'meetingPoint', 'transport', 'contacts'])
        ->and($detail['place']['name'])->toBe('Sala secreta del sotano');

    expect(json_encode($summary, JSON_THROW_ON_ERROR))->not->toContain('Sala secreta');
    expect(json_encode([$detail, $summary], JSON_THROW_ON_ERROR))->not->toContain($room->id);
});
