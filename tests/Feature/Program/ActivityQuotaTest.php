<?php

declare(strict_types=1);

use App\Actions\Program\CreateActivity;
use App\Actions\Program\UpdateActivity;
use App\Enums\OrganizationRole;
use App\Exceptions\Domain\ActivityRoomConflict;
use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Room;
use App\Models\Speaker;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

/*
 * Plan 035, F3 (parte 1): cupo de la actividad y lugares externos. El cupo es opcional e informativo; un lugar
 * externo admite actividades simultáneas y una sala no; el aviso de ponente repetido sigue en los dos.
 */

afterEach(function (): void {
    CurrentOrganization::forget();
});

/** @return array{0: Event, 1: User} evento de una organización nueva y su administrador */
function quotaScenario(): array
{
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);

    return [Event::factory()->create(['organization_id' => $organization->id]), $admin];
}

/** @param  array<string, mixed>  $attributes */
function quotaPlace(Event $event, bool $external, array $attributes = []): Room
{
    return CurrentOrganization::run($event->organization, function () use ($event, $external, $attributes): Room {
        $factory = $external ? Room::factory()->external() : Room::factory();

        return $factory->create(['event_id' => $event->id, ...$attributes]);
    });
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function quotaData(array $overrides = []): array
{
    return array_replace([
        'title' => 'Taller de prueba',
        'starts_on' => '2027-03-10',
        'ends_on' => '2027-03-10',
        'starts_at' => '10:00:00',
        'ends_at' => '11:00:00',
    ], $overrides);
}

it('CA-035-10: the quota is a whole number from 1 to 100000, or empty for no quota', function (mixed $quota, bool $valid): void {
    [$event, $admin] = quotaScenario();
    $create = fn () => app(CreateActivity::class)->handle($admin, $event, quotaData(['quota' => $quota]));

    if ($valid) {
        expect($create()->fresh()->quota)->toBe(is_numeric($quota) ? (int) $quota : null);

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
    'negative' => [-4, false],
    'decimal' => ['2.5', false],
    'over the limit' => [100001, false],
    'words' => ['varios', false],
]);

it('CA-035-10: the quota works with a room, with an external place and with no place', function (): void {
    [$event, $admin] = quotaScenario();
    $room = quotaPlace($event, false);
    $park = quotaPlace($event, true);

    $inRoom = app(CreateActivity::class)->handle($admin, $event, quotaData(['room_id' => $room->id, 'quota' => 30]));
    $inPark = app(CreateActivity::class)->handle($admin, $event, quotaData(['title' => 'En el parque', 'room_id' => $park->id, 'quota' => 20]));
    $nowhere = app(CreateActivity::class)->handle($admin, $event, quotaData(['title' => 'Sin lugar', 'quota' => 10]));

    expect([$inRoom->fresh()->quota, $inPark->fresh()->quota, $nowhere->fresh()->quota])->toBe([30, 20, 10]);
});

it('CA-035-10: editing keeps the quota when the data does not mention it, changes it, and clears it with null', function (): void {
    [$event, $admin] = quotaScenario();
    $activity = app(CreateActivity::class)->handle($admin, $event, quotaData(['quota' => 25]));

    app(UpdateActivity::class)->handle($admin, $activity, quotaData(['title' => 'Renombrada']));
    expect($activity->fresh()->quota)->toBe(25);

    app(UpdateActivity::class)->handle($admin, $activity, quotaData(['title' => 'Renombrada', 'quota' => 40]));
    expect($activity->fresh()->quota)->toBe(40);

    app(UpdateActivity::class)->handle($admin, $activity, quotaData(['title' => 'Renombrada', 'quota' => null]));
    expect($activity->fresh()->quota)->toBeNull();
});

it('CA-035-11: a quota above the capacity of the place saves with a warning that carries both numbers', function (): void {
    [$event, $admin] = quotaScenario();
    $place = quotaPlace($event, true, ['capacity' => 30]);

    $created = app(CreateActivity::class)->handle($admin, $event, quotaData(['room_id' => $place->id, 'quota' => 50]));

    expect($created->fresh()->quota)->toBe(50)
        ->and($created->quotaWarning)->toBe(['quota' => 50, 'capacity' => 30]);

    $updated = app(UpdateActivity::class)->handle($admin, $created, quotaData(['room_id' => $place->id, 'quota' => 60]));

    expect($updated->quotaWarning)->toBe(['quota' => 60, 'capacity' => 30]);
});

it('CA-035-11: there is no warning when the quota fits, or the quota or the capacity are missing', function (): void {
    [$event, $admin] = quotaScenario();
    $withCapacity = quotaPlace($event, false, ['capacity' => 30]);
    $withoutCapacity = quotaPlace($event, false);

    $fits = app(CreateActivity::class)->handle($admin, $event, quotaData(['room_id' => $withCapacity->id, 'quota' => 30]));
    $noQuota = app(CreateActivity::class)->handle($admin, $event, quotaData(['title' => 'Sin cupo', 'room_id' => $withCapacity->id, 'starts_at' => '12:00:00', 'ends_at' => '13:00:00']));
    $noCapacity = app(CreateActivity::class)->handle($admin, $event, quotaData(['title' => 'Sin capacidad', 'room_id' => $withoutCapacity->id, 'quota' => 500]));
    $noPlace = app(CreateActivity::class)->handle($admin, $event, quotaData(['title' => 'Sin lugar', 'quota' => 500]));

    expect([$fits->quotaWarning, $noQuota->quotaWarning, $noCapacity->quotaWarning, $noPlace->quotaWarning])->toBe([null, null, null, null]);
});

it('CA-035-16: two activities at the same time in the same external place are accepted', function (): void {
    [$event, $admin] = quotaScenario();
    $park = quotaPlace($event, true);

    $first = app(CreateActivity::class)->handle($admin, $event, quotaData(['room_id' => $park->id]));
    $second = app(CreateActivity::class)->handle($admin, $event, quotaData(['title' => 'Otro taller', 'room_id' => $park->id]));
    $third = app(CreateActivity::class)->handle($admin, $event, quotaData(['title' => 'Y otro', 'room_id' => $park->id, 'starts_at' => '10:30:00', 'ends_at' => '11:30:00']));

    expect(CurrentOrganization::run($event->organization, fn () => EventActivity::query()->where('room_id', $park->id)->count()))->toBe(3)
        ->and([$first->fresh()->room_id, $second->fresh()->room_id, $third->fresh()->room_id])->each->toBe($park->id);
});

it('CA-035-16: moving an activity onto the time of another one in an external place is accepted', function (): void {
    [$event, $admin] = quotaScenario();
    $park = quotaPlace($event, true);
    app(CreateActivity::class)->handle($admin, $event, quotaData(['room_id' => $park->id]));
    $other = app(CreateActivity::class)->handle($admin, $event, quotaData(['title' => 'Otro', 'room_id' => $park->id, 'starts_at' => '15:00:00', 'ends_at' => '16:00:00']));

    app(UpdateActivity::class)->handle($admin, $other, quotaData(['title' => 'Otro', 'room_id' => $park->id]));

    expect($other->fresh()->starts_at)->toBe('10:00:00');
});

it('CA-035-16: a room still refuses an overlapping activity', function (): void {
    [$event, $admin] = quotaScenario();
    $room = quotaPlace($event, false);
    app(CreateActivity::class)->handle($admin, $event, quotaData(['room_id' => $room->id]));

    expect(fn () => app(CreateActivity::class)->handle($admin, $event, quotaData(['title' => 'Choca', 'room_id' => $room->id])))
        ->toThrow(ActivityRoomConflict::class);
});

it('CA-035-17: a speaker in two overlapping activities is warned even when both are in external places', function (): void {
    [$event, $admin] = quotaScenario();
    $park = quotaPlace($event, true, ['name' => 'Parque A']);
    $plaza = quotaPlace($event, true, ['name' => 'Plaza B']);
    $speaker = CurrentOrganization::run($event->organization, fn (): Speaker => Speaker::factory()->create(['event_id' => $event->id, 'first_name' => 'Ada', 'last_name' => 'Lovelace']));
    $first = app(CreateActivity::class)->handle($admin, $event, quotaData(['title' => 'Primero', 'room_id' => $park->id, 'speaker_ids' => [$speaker->id]]));

    $second = app(CreateActivity::class)->handle($admin, $event, quotaData(['title' => 'Segundo', 'room_id' => $plaza->id, 'speaker_ids' => [$speaker->id]]));

    expect($first->scheduleWarnings)->toBe([])
        ->and($second->scheduleWarnings)->toHaveCount(1)
        ->and($second->scheduleWarnings[0]['activity'])->toBe('Primero');
});

it('CA-035-20: a reviewer cannot set the quota or the place of an activity', function (): void {
    [$event, $admin] = quotaScenario();
    $reviewer = Memberships::attach(User::factory()->create(), $event->organization, OrganizationRole::Reviewer);
    $park = quotaPlace($event, true);
    $activity = app(CreateActivity::class)->handle($admin, $event, quotaData());

    expect(fn () => app(CreateActivity::class)->handle($reviewer, $event, quotaData(['quota' => 10, 'room_id' => $park->id])))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateActivity::class)->handle($reviewer, $activity, quotaData(['quota' => 10, 'room_id' => $park->id])))
        ->toThrow(AuthorizationException::class);

    expect(DB::table('event_activities')->where('id', $activity->id)->value('quota'))->toBeNull();
});

it('CA-035-20: a place of another event or organization is refused as nonexistent', function (): void {
    [$event, $admin] = quotaScenario();
    $otherEvent = Event::factory()->create(['organization_id' => $event->organization_id]);
    $foreign = Event::factory()->create();

    foreach ([quotaPlace($otherEvent, true), quotaPlace($foreign, true)] as $place) {
        expect(fn () => app(CreateActivity::class)->handle($admin, $event, quotaData(['room_id' => $place->id, 'quota' => 10])))
            ->toThrow(ValidationException::class);
    }
});

it('CA-035-18: the existing calls without quota keep working with the same arguments', function (): void {
    [$event, $admin] = quotaScenario();

    $created = app(CreateActivity::class)->handle($admin, $event, quotaData());
    $updated = app(UpdateActivity::class)->handle($admin, $created, quotaData(['title' => 'Otro nombre']));

    expect($updated->fresh()->title)->toBe('Otro nombre')
        ->and($updated->fresh()->quota)->toBeNull()
        ->and($updated->quotaWarning)->toBeNull();
});
