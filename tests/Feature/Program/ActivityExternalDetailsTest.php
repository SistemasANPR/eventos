<?php

declare(strict_types=1);

use App\Actions\Program\CreateActivity;
use App\Actions\Program\UpdateActivity;
use App\Enums\OrganizationRole;
use App\Exceptions\Domain\ActivityRoomConflict;
use App\Models\ActivityContact;
use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Room;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity as AuditLog;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

/*
 * Plan 035, F3 (parte 2): punto de reunión, transporte y responsables de una actividad en un lugar externo
 * (CA-035-12 a 15, 19 y 20; DA-4, DA-5 y DA-11). Solo existen con un lugar externo; al dejar de serlo se borran.
 */

afterEach(function (): void {
    CurrentOrganization::forget();
});

/** @return array{0: Event, 1: User} evento de una organización nueva y su administrador */
function extScenario(): array
{
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);

    return [Event::factory()->create(['organization_id' => $organization->id]), $admin];
}

function extPlace(Event $event, bool $external, string $name = 'Lugar'): Room
{
    return CurrentOrganization::run($event->organization, function () use ($event, $external, $name): Room {
        $factory = $external ? Room::factory()->external() : Room::factory();

        return $factory->create(['event_id' => $event->id, 'name' => $name]);
    });
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function extData(array $overrides = []): array
{
    return array_replace([
        'title' => 'Taller en el parque',
        'starts_on' => '2027-03-10',
        'ends_on' => '2027-03-10',
        'starts_at' => '10:00:00',
        'ends_at' => '11:00:00',
    ], $overrides);
}

/** @return list<array<string, mixed>> los responsables de la actividad tal como están en la base, en orden */
function extStored(EventActivity $activity): array
{
    return DB::table('activity_contacts')->where('activity_id', $activity->id)->orderBy('position')
        ->get(['name', 'phone', 'email', 'position', 'is_public'])
        ->map(fn (object $row): array => (array) $row)
        ->all();
}

/** @return array<string, mixed> */
function extRow(EventActivity $activity): array
{
    return (array) DB::table('event_activities')->where('id', $activity->id)->first(['meeting_point', 'transport_info', 'room_id']);
}

it('CA-035-12: an activity in an external place keeps its meeting point, transport and contacts in order', function (): void {
    [$event, $admin] = extScenario();
    $park = extPlace($event, true);

    $activity = app(CreateActivity::class)->handle($admin, $event, extData([
        'room_id' => $park->id,
        'meeting_point' => '  Puerta norte, junto al quiosco  ',
        'transport_info' => 'Salida a las 8:30 desde el lobby del hotel',
        'contacts' => [
            ['name' => 'Ana Torres', 'phone' => '+52 55 1234-5678', 'email' => 'ana@example.test', 'is_public' => true],
            ['name' => 'Luis Mora'],
        ],
    ]));

    expect(extRow($activity))->toMatchArray([
        'meeting_point' => 'Puerta norte, junto al quiosco',
        'transport_info' => 'Salida a las 8:30 desde el lobby del hotel',
    ])->and(extStored($activity))->toBe([
        ['name' => 'Ana Torres', 'phone' => '+52 55 1234-5678', 'email' => 'ana@example.test', 'position' => 1, 'is_public' => true],
        ['name' => 'Luis Mora', 'phone' => null, 'email' => null, 'position' => 2, 'is_public' => false],
    ]);
});

it('CA-035-12: empty texts are stored as null and a missing transport means no transport', function (): void {
    [$event, $admin] = extScenario();
    $park = extPlace($event, true);

    $activity = app(CreateActivity::class)->handle($admin, $event, extData([
        'room_id' => $park->id, 'meeting_point' => '   ', 'transport_info' => '',
        'contacts' => [['name' => '  Ana  ', 'phone' => '', 'email' => '  ']],
    ]));

    expect(extRow($activity))->toMatchArray(['meeting_point' => null, 'transport_info' => null])
        ->and(extStored($activity)[0])->toMatchArray(['name' => 'Ana', 'phone' => null, 'email' => null]);
});

it('CA-035-12: the meeting point and the transport accept up to 500 characters', function (string $field, int $length, bool $valid): void {
    [$event, $admin] = extScenario();
    $park = extPlace($event, true);
    $create = fn () => app(CreateActivity::class)->handle($admin, $event, extData(['room_id' => $park->id, $field => str_repeat('a', $length)]));

    if ($valid) {
        expect(extRow($create())[$field])->toHaveLength($length);

        return;
    }

    expect($create)->toThrow(ValidationException::class);
})->with([
    'meeting point at the limit' => ['meeting_point', 500, true],
    'meeting point too long' => ['meeting_point', 501, false],
    'transport at the limit' => ['transport_info', 500, true],
    'transport too long' => ['transport_info', 501, false],
]);

it('CA-035-15: each contact needs a name, a valid phone and email, and at most five are kept', function (array $contact, bool $valid): void {
    [$event, $admin] = extScenario();
    $park = extPlace($event, true);
    $create = fn () => app(CreateActivity::class)->handle($admin, $event, extData(['room_id' => $park->id, 'contacts' => [$contact]]));

    if ($valid) {
        expect(extStored($create()))->toHaveCount(1);

        return;
    }

    expect($create)->toThrow(ValidationException::class);
})->with([
    'name only' => [['name' => 'Ana'], true],
    'name at the limit' => [['name' => str_repeat('a', 120)], true],
    'phone with symbols' => [['name' => 'Ana', 'phone' => '+52 (55) 1234-5678'], true],
    'valid email' => [['name' => 'Ana', 'email' => 'ana@example.test'], true],
    'missing name' => [['phone' => '5512345678'], false],
    'empty name' => [['name' => '  ', 'email' => 'ana@example.test'], false],
    'name too long' => [['name' => str_repeat('a', 121)], false],
    'phone with letters' => [['name' => 'Ana', 'phone' => '55-ABCD'], false],
    'phone too long' => [['name' => 'Ana', 'phone' => str_repeat('1', 41)], false],
    'invalid email' => [['name' => 'Ana', 'email' => 'no-es-correo'], false],
    'email too long' => [['name' => 'Ana', 'email' => str_repeat('a', 250).'@x.test'], false],
]);

it('CA-035-15: five contacts are accepted and a sixth is refused with nothing written', function (): void {
    [$event, $admin] = extScenario();
    $park = extPlace($event, true);
    $contacts = fn (int $n): array => array_map(fn (int $i): array => ['name' => "Responsable {$i}"], range(1, $n));

    $ok = app(CreateActivity::class)->handle($admin, $event, extData(['room_id' => $park->id, 'contacts' => $contacts(5)]));

    expect(extStored($ok))->toHaveCount(5)
        ->and(fn () => app(CreateActivity::class)->handle($admin, $event, extData(['title' => 'Seis', 'room_id' => $park->id, 'contacts' => $contacts(6)])))
        ->toThrow(ValidationException::class);

    expect(CurrentOrganization::run($event->organization, fn () => EventActivity::query()->where('event_id', $event->id)->count()))->toBe(1);
});

it('CA-035-13: a room or no place refuses meeting point, transport and contacts and writes nothing', function (string $field, mixed $value): void {
    [$event, $admin] = extScenario();
    $room = extPlace($event, false);

    foreach ([['room_id' => $room->id], []] as $place) {
        $error = null;

        try {
            app(CreateActivity::class)->handle($admin, $event, extData([...$place, $field => $value]));
        } catch (ValidationException $exception) {
            $error = $exception->errors();
        }

        expect($error)->not->toBeNull()->and($error)->toHaveKey($field);
    }

    expect(CurrentOrganization::run($event->organization, fn () => EventActivity::query()->where('event_id', $event->id)->count()))->toBe(0)
        ->and(DB::table('activity_contacts')->count())->toBe(0);
})->with([
    'meeting point' => ['meeting_point', 'Puerta norte'],
    'transport' => ['transport_info', 'Autobús a las 8:00'],
    'contacts' => ['contacts', [['name' => 'Ana']]],
]);

it('CA-035-13: empty values for those fields are harmless with a room, as the panel sends them', function (): void {
    [$event, $admin] = extScenario();
    $room = extPlace($event, false);

    $activity = app(CreateActivity::class)->handle($admin, $event, extData(['room_id' => $room->id, 'meeting_point' => null, 'transport_info' => '', 'contacts' => []]));

    expect(extRow($activity))->toMatchArray(['meeting_point' => null, 'transport_info' => null])->and(extStored($activity))->toBe([]);
});

it('CA-035-13: editing an activity of a room refuses external details too', function (): void {
    [$event, $admin] = extScenario();
    $room = extPlace($event, false);
    $activity = app(CreateActivity::class)->handle($admin, $event, extData(['room_id' => $room->id]));

    expect(fn () => app(UpdateActivity::class)->handle($admin, $activity, extData(['meeting_point' => 'Puerta norte'])))
        ->toThrow(ValidationException::class);

    expect(extRow($activity)['meeting_point'])->toBeNull();
});

it('CA-035-12: editing keeps what the data does not mention, changes what it mentions and clears with null', function (): void {
    [$event, $admin] = extScenario();
    $park = extPlace($event, true);
    $activity = app(CreateActivity::class)->handle($admin, $event, extData([
        'room_id' => $park->id, 'meeting_point' => 'Puerta norte', 'transport_info' => 'Autobús', 'contacts' => [['name' => 'Ana']],
    ]));

    app(UpdateActivity::class)->handle($admin, $activity, extData(['title' => 'Renombrado']));
    expect(extRow($activity))->toMatchArray(['meeting_point' => 'Puerta norte', 'transport_info' => 'Autobús'])
        ->and(extStored($activity))->toHaveCount(1);

    app(UpdateActivity::class)->handle($admin, $activity, extData(['title' => 'Renombrado', 'meeting_point' => 'Kiosco', 'transport_info' => null, 'contacts' => [['name' => 'Luis'], ['name' => 'Eva', 'is_public' => true]]]));
    expect(extRow($activity))->toMatchArray(['meeting_point' => 'Kiosco', 'transport_info' => null])
        ->and(array_column(extStored($activity), 'name'))->toBe(['Luis', 'Eva'])
        ->and(array_column(extStored($activity), 'position'))->toBe([1, 2]);

    app(UpdateActivity::class)->handle($admin, $activity, extData(['title' => 'Renombrado', 'contacts' => []]));
    expect(extStored($activity))->toBe([]);
});

it('CA-035-14: moving the activity to a room or to no place deletes meeting point, transport and contacts', function (?string $target): void {
    [$event, $admin] = extScenario();
    $park = extPlace($event, true, 'Parque');
    $room = extPlace($event, false, 'Sala');
    $activity = app(CreateActivity::class)->handle($admin, $event, extData([
        'room_id' => $park->id, 'meeting_point' => 'Puerta norte', 'transport_info' => 'Autobús', 'contacts' => [['name' => 'Ana'], ['name' => 'Luis']],
    ]));

    app(UpdateActivity::class)->handle($admin, $activity, extData(['room_id' => $target === 'room' ? $room->id : null]));

    expect(extRow($activity))->toMatchArray(['meeting_point' => null, 'transport_info' => null, 'room_id' => $target === 'room' ? $room->id : null])
        ->and(extStored($activity))->toBe([]);
})->with(['to a room' => ['room'], 'to no place' => [null]]);

it('CA-035-14: the removal is audited with counts and without personal data', function (): void {
    [$event, $admin] = extScenario();
    $park = extPlace($event, true);
    $room = extPlace($event, false, 'Sala');
    $activity = app(CreateActivity::class)->handle($admin, $event, extData([
        'room_id' => $park->id, 'meeting_point' => 'Puerta norte secreta', 'contacts' => [['name' => 'Ana Torres Ruiz', 'phone' => '5512345678', 'email' => 'ana.torres@example.test']],
    ]));
    app(UpdateActivity::class)->handle($admin, $activity, extData(['room_id' => $room->id]));

    $logs = AuditLog::query()->whereIn('event', ['activity_created', 'activity_updated'])->get();
    $json = strtolower((string) $logs->toJson());

    expect($logs)->toHaveCount(2);

    foreach (['ana torres', '5512345678', 'ana.torres', 'puerta norte secreta'] as $secret) {
        expect($json)->not->toContain($secret);
    }

    $updated = $logs->firstWhere('event', 'activity_updated');
    expect($updated->properties['changed'])->toContain('meeting_point')->toContain('contacts')
        ->and($updated->properties['contacts'])->toBe(['before' => 1, 'after' => 0]);
    expect($logs->firstWhere('event', 'activity_created')->properties['contacts'])->toBe(['before' => 0, 'after' => 1]);
});

it('CA-035-14: an edit that leaves the same contacts does not touch them', function (): void {
    [$event, $admin] = extScenario();
    $park = extPlace($event, true);
    $activity = app(CreateActivity::class)->handle($admin, $event, extData(['room_id' => $park->id, 'contacts' => [['name' => 'Ana', 'email' => 'ana@example.test']]]));
    $before = DB::table('activity_contacts')->where('activity_id', $activity->id)->value('id');

    app(UpdateActivity::class)->handle($admin, $activity, extData(['room_id' => $park->id, 'contacts' => [['name' => 'Ana', 'email' => 'ana@example.test']]]));
    $updated = AuditLog::query()->where('event', 'activity_updated')->latest('id')->firstOrFail();

    expect(DB::table('activity_contacts')->where('activity_id', $activity->id)->value('id'))->toBe($before)
        ->and($updated->properties['changed'])->not->toContain('contacts');
});

it('CA-035-19: a failure after the external details were written leaves nothing behind', function (): void {
    [$event, $admin] = extScenario();
    $park = extPlace($event, true);
    $activity = app(CreateActivity::class)->handle($admin, $event, extData(['room_id' => $park->id, 'meeting_point' => 'Puerta norte', 'contacts' => [['name' => 'Ana']]]));
    $room = extPlace($event, false, 'Sala');
    CurrentOrganization::run($event->organization, fn () => EventActivity::factory()->scheduled('2027-03-11', '09:00:00', '10:00:00')->create(['event_id' => $event->id, 'room_id' => $room->id, 'title' => 'Ocupa la sala']));
    $before = [extRow($activity), extStored($activity)];

    // El cambio de lugar choca con otra actividad en la sala: se rechaza todo, también los datos externos.
    expect(fn () => app(UpdateActivity::class)->handle($admin, $activity, extData(['title' => 'Cambiada', 'room_id' => $room->id, 'starts_on' => '2027-03-11', 'ends_on' => '2027-03-11', 'starts_at' => '09:30:00', 'ends_at' => '09:45:00'])))
        ->toThrow(ActivityRoomConflict::class);

    expect([extRow($activity), extStored($activity)])->toBe($before)
        ->and($activity->fresh()->title)->toBe('Taller en el parque');
});

it('CA-035-19: an invalid contact in a multi-step edit changes nothing', function (): void {
    [$event, $admin] = extScenario();
    $park = extPlace($event, true);
    $activity = app(CreateActivity::class)->handle($admin, $event, extData(['room_id' => $park->id, 'meeting_point' => 'Puerta norte', 'contacts' => [['name' => 'Ana']]]));
    $before = [extRow($activity), extStored($activity)];

    expect(fn () => app(UpdateActivity::class)->handle($admin, $activity, extData(['title' => 'Cambiada', 'meeting_point' => 'Kiosco', 'contacts' => [['name' => 'Luis'], ['email' => 'sin-nombre@example.test']]])))
        ->toThrow(ValidationException::class);

    expect([extRow($activity), extStored($activity)])->toBe($before)->and($activity->fresh()->title)->toBe('Taller en el parque');
});

it('CA-035-20: a reviewer or a user of another organization cannot set external details', function (): void {
    [$event, $admin] = extScenario();
    $reviewer = Memberships::attach(User::factory()->create(), $event->organization, OrganizationRole::Reviewer);
    [, $stranger] = extScenario();
    $park = extPlace($event, true);
    $activity = app(CreateActivity::class)->handle($admin, $event, extData(['room_id' => $park->id]));
    $payload = extData(['room_id' => $park->id, 'meeting_point' => 'Puerta norte', 'contacts' => [['name' => 'Ana']]]);

    foreach ([$reviewer, $stranger] as $intruder) {
        expect(fn () => app(CreateActivity::class)->handle($intruder, $event, $payload))->toThrow(AuthorizationException::class)
            ->and(fn () => app(UpdateActivity::class)->handle($intruder, $activity, $payload))->toThrow(AuthorizationException::class);
    }

    expect(extRow($activity)['meeting_point'])->toBeNull()->and(extStored($activity))->toBe([]);
});

it('CA-035-20: a contact belongs to the organization of its activity', function (): void {
    [$event, $admin] = extScenario();
    $park = extPlace($event, true);
    $activity = app(CreateActivity::class)->handle($admin, $event, extData(['room_id' => $park->id, 'contacts' => [['name' => 'Ana']]]));

    $contact = DB::table('activity_contacts')->where('activity_id', $activity->id)->first();

    expect($contact->organization_id)->toBe($event->organization_id)
        ->and($contact->event_id)->toBe($event->id)
        ->and(CurrentOrganization::run($event->organization, fn () => ActivityContact::query()->count()))->toBe(1);
});

it('CA-035-18: the existing calls without external details keep working', function (): void {
    [$event, $admin] = extScenario();

    $created = app(CreateActivity::class)->handle($admin, $event, extData());
    $updated = app(UpdateActivity::class)->handle($admin, $created, extData(['title' => 'Otro nombre']));

    expect($updated->fresh()->title)->toBe('Otro nombre')->and(extStored($updated))->toBe([]);
});
