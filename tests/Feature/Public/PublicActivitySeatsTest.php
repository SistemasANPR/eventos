<?php

declare(strict_types=1);

use App\Actions\Program\FindPublicActivity;
use App\Models\ActivityContact;
use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Organization;
use App\Models\Room;
use App\Support\Program\PublicActivityData;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../Support/public-program-helpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
});

afterEach(function (): void {
    CurrentOrganization::forget();
});

/*
 * Plan 035, F4: lo que el sitio público sabe del cupo, del lugar y de los datos de un lugar externo. En el
 * programa solo la insignia «Cupo limitado»; en el detalle, el cupo, el lugar, el punto de reunión, el transporte
 * y los responsables públicos (lista cerrada de claves, DA-5, DA-6 y DA-13).
 */

/** @param  array<string, mixed>  $attributes */
function seatsPlace(Event $event, bool $external, array $attributes = []): Room
{
    return ppIn($event, fn (): Room => ($external ? Room::factory()->external() : Room::factory())->create(['event_id' => $event->id, ...$attributes]));
}

/** @param  array<string, mixed>  $attributes */
function seatsContact(EventActivity $activity, int $position, array $attributes = []): ActivityContact
{
    return CurrentOrganization::run(Organization::query()->findOrFail($activity->organization_id), fn (): ActivityContact => ActivityContact::factory()->create([
        'activity_id' => $activity->id, 'event_id' => $activity->event_id, 'organization_id' => $activity->organization_id,
        'position' => $position, ...$attributes,
    ]));
}

/** @return array{0: array<string, mixed>, 1: array<string, mixed>} resumen y detalle de la actividad, como los ve el público */
function seatsProps(Event $event, EventActivity $activity): array
{
    $found = ppIn($event, fn () => app(FindPublicActivity::class)->handle($event, $activity->id));

    return ppIn($event, fn (): array => [PublicActivityData::summary($found, $event), PublicActivityData::detail($found, $event)]);
}

it('CA-035-24: the summary only says whether the quota is limited and never carries a number', function (): void {
    $event = ppEvent();
    $limited = ppActivity($event, ['title' => 'Con cupo', 'quota' => 7351]);
    $open = ppActivity($event, ['title' => 'Sin cupo']);

    [$limitedSummary] = seatsProps($event, $limited);
    [$openSummary] = seatsProps($event, $open);

    expect($limitedSummary['limitedSeats'])->toBeTrue()
        ->and($openSummary['limitedSeats'])->toBeFalse()
        ->and(json_encode($limitedSummary, JSON_THROW_ON_ERROR))->not->toContain('7351');
});

it('CA-035-24: the summary keys are the closed list plus limitedSeats and nothing about the place', function (): void {
    $event = ppEvent();
    $park = seatsPlace($event, true, ['name' => 'Parque secreto', 'capacity' => 90, 'capacity_is_public' => true, 'address' => 'Calle oculta 9']);
    $activity = ppActivity($event, ['quota' => 20, 'room_id' => $park->id, 'meeting_point' => 'Puerta norte', 'transport_info' => 'Autobus 8:00']);

    [$summary] = seatsProps($event, $activity);

    expect(array_keys($summary))->toBe(['id', 'title', 'summary', 'date', 'endDate', 'startTime', 'endTime', 'categories', 'speakers', 'iconUrl', 'limitedSeats', 'url']);

    $emitted = json_encode($summary, JSON_THROW_ON_ERROR);

    foreach (['Parque secreto', 'Calle oculta', 'Puerta norte'] as $secret) {
        expect($emitted)->not->toContain($secret);
    }
});

it('CA-035-25: the detail shows the quota number when there is one and null when there is not', function (): void {
    $event = ppEvent();
    $limited = ppActivity($event, ['quota' => 25]);
    $open = ppActivity($event, ['title' => 'Otra']);

    expect(seatsProps($event, $limited)[1]['seats'])->toBe(25)
        ->and(seatsProps($event, $open)[1]['seats'])->toBeNull();
});

it('CA-035-26: the detail names the place and, only when they exist, its address and map', function (): void {
    $event = ppEvent();
    $withData = seatsPlace($event, false, ['name' => 'Auditorio', 'address' => 'Edificio A, piso 2', 'map_url' => 'https://maps.example.test/auditorio']);
    $bare = seatsPlace($event, false, ['name' => 'Sala B']);

    $full = seatsProps($event, ppActivity($event, ['room_id' => $withData->id]))[1]['place'];
    $minimal = seatsProps($event, ppActivity($event, ['title' => 'Otra', 'room_id' => $bare->id]))[1]['place'];

    expect($full)->toBe(['name' => 'Auditorio', 'isExternal' => false, 'address' => 'Edificio A, piso 2', 'mapUrl' => 'https://maps.example.test/auditorio', 'capacity' => null])
        ->and($minimal)->toBe(['name' => 'Sala B', 'isExternal' => false, 'address' => null, 'mapUrl' => null, 'capacity' => null]);
});

it('CA-035-26: an activity without place has no place block', function (): void {
    $event = ppEvent();

    expect(seatsProps($event, ppActivity($event))[1]['place'])->toBeNull();
});

it('CA-035-26: the capacity of the place only goes out when the organizer made it public', function (): void {
    $event = ppEvent();
    $private = seatsPlace($event, false, ['name' => 'Privada', 'capacity' => 111, 'capacity_is_public' => false]);
    $public = seatsPlace($event, false, ['name' => 'Publica', 'capacity' => 222, 'capacity_is_public' => true]);
    $publicWithoutCapacity = seatsPlace($event, false, ['name' => 'Sin dato', 'capacity' => null, 'capacity_is_public' => true]);

    $hidden = seatsProps($event, ppActivity($event, ['room_id' => $private->id]))[1];
    $shown = seatsProps($event, ppActivity($event, ['title' => 'Dos', 'room_id' => $public->id]))[1];
    $empty = seatsProps($event, ppActivity($event, ['title' => 'Tres', 'room_id' => $publicWithoutCapacity->id]))[1];

    expect($hidden['place']['capacity'])->toBeNull()
        ->and(json_encode($hidden, JSON_THROW_ON_ERROR))->not->toContain('111')
        ->and($shown['place']['capacity'])->toBe(222)
        ->and($empty['place']['capacity'])->toBeNull();
});

it('CA-035-26: a map link that is not https never goes out', function (string $url): void {
    $event = ppEvent();
    $place = seatsPlace($event, true, ['name' => 'Parque']);
    ppIn($event, fn () => Room::query()->whereKey($place->id)->update(['map_url' => $url]));

    expect(seatsProps($event, ppActivity($event, ['room_id' => $place->id]))[1]['place']['mapUrl'])->toBeNull();
})->with([
    'http' => 'http://maps.example.test/a',
    'javascript' => 'javascript:alert(1)',
    'data' => 'data:text/html;base64,PHNjcmlwdD4=',
    'relative' => '/mapa',
    'text' => 'cerca del parque',
]);

it('CA-035-27: the meeting point and the transport come out for an external place and are null when empty', function (): void {
    $event = ppEvent();
    $park = seatsPlace($event, true, ['name' => 'Parque Lineal']);

    $with = seatsProps($event, ppActivity($event, ['room_id' => $park->id, 'meeting_point' => 'Puerta norte', 'transport_info' => 'Sale del hotel a las 8:00']))[1];
    $without = seatsProps($event, ppActivity($event, ['title' => 'Otra', 'room_id' => $park->id]))[1];

    expect($with['place']['isExternal'])->toBeTrue()
        ->and($with['meetingPoint'])->toBe('Puerta norte')
        ->and($with['transport'])->toBe('Sale del hotel a las 8:00')
        ->and($without['meetingPoint'])->toBeNull()
        ->and($without['transport'])->toBeNull();
});

it('CA-035-27: external data left on an activity whose place is a room or missing is not published', function (): void {
    $event = ppEvent();
    $room = seatsPlace($event, false, ['name' => 'Sala']);

    $inRoom = seatsProps($event, ppActivity($event, ['room_id' => $room->id, 'meeting_point' => 'Residuo', 'transport_info' => 'Residuo']))[1];
    $nowhere = seatsProps($event, ppActivity($event, ['title' => 'Otra', 'meeting_point' => 'Residuo', 'transport_info' => 'Residuo']))[1];

    expect([$inRoom['meetingPoint'], $inRoom['transport'], $nowhere['meetingPoint'], $nowhere['transport']])->toBe([null, null, null, null]);
});

it('CA-035-28: only the public contacts go out, in their order and with the data they have', function (): void {
    $event = ppEvent();
    $park = seatsPlace($event, true);
    $activity = ppActivity($event, ['room_id' => $park->id]);
    seatsContact($activity, 1, ['name' => 'Interna Privada', 'email' => 'privada@example.test', 'phone' => '55 0000 1111', 'is_public' => false]);
    seatsContact($activity, 3, ['name' => 'Luis Publico', 'email' => null, 'phone' => '55 2222 3333', 'is_public' => true]);
    seatsContact($activity, 2, ['name' => 'Ana Publica', 'email' => 'ana@example.test', 'phone' => null, 'is_public' => true]);

    [$summary, $detail] = seatsProps($event, $activity);
    $emitted = json_encode([$summary, $detail], JSON_THROW_ON_ERROR);

    expect($detail['contacts'])->toBe([
        ['name' => 'Ana Publica', 'phone' => null, 'email' => 'ana@example.test'],
        ['name' => 'Luis Publico', 'phone' => '55 2222 3333', 'email' => null],
    ])
        ->and(array_key_exists('contacts', $summary))->toBeFalse();

    foreach (['Interna Privada', 'privada@example.test', '55 0000 1111'] as $secret) {
        expect($emitted)->not->toContain($secret);
    }
});

it('CA-035-28: an activity with no public contacts sends an empty list', function (): void {
    $event = ppEvent();
    $park = seatsPlace($event, true);
    $activity = ppActivity($event, ['room_id' => $park->id]);
    seatsContact($activity, 1, ['is_public' => false]);

    expect(seatsProps($event, $activity)[1]['contacts'])->toBe([])
        ->and(seatsProps($event, ppActivity($event, ['title' => 'Otra']))[1]['contacts'])->toBe([]);
});

it('CA-035-29: with every field filled the detail has exactly the closed key list and no internal value', function (): void {
    $event = ppEvent();
    $park = seatsPlace($event, true, [
        'name' => 'Parque Lineal', 'capacity' => 60, 'capacity_is_public' => true, 'address' => 'Entrada norte', 'map_url' => 'https://maps.example.test/p',
    ]);
    $activity = ppActivity($event, [
        'title' => 'Taller', 'room_id' => $park->id, 'quota' => 30, 'meeting_point' => 'Quiosco', 'transport_info' => 'Autobus',
        'summary' => 'Resumen', 'description' => '<p>Texto</p>',
    ]);
    $public = seatsContact($activity, 1, ['name' => 'Responsable', 'email' => 'resp@example.test', 'phone' => '55 1111 2222', 'is_public' => true]);
    $private = seatsContact($activity, 2, ['name' => 'Interno', 'email' => 'interno@example.test', 'is_public' => false]);

    [, $detail] = seatsProps($event, $activity);
    $emitted = json_encode($detail, JSON_THROW_ON_ERROR);

    expect(array_keys($detail))->toBe([
        'id', 'title', 'summary', 'date', 'endDate', 'startTime', 'endTime', 'categories', 'speakers', 'iconUrl', 'limitedSeats', 'url',
        'descriptionHtml', 'imageUrl', 'seats', 'place', 'meetingPoint', 'transport', 'contacts',
    ])
        ->and(array_keys($detail['place']))->toBe(['name', 'isExternal', 'address', 'mapUrl', 'capacity'])
        ->and(array_keys($detail['contacts'][0]))->toBe(['name', 'phone', 'email']);

    foreach ([$park->id, $public->id, $private->id, $event->organization_id, $event->id] as $internal) {
        expect($emitted)->not->toContain($internal);
    }

    // El id de la actividad sí sale (es parte de su dirección pública), pero ninguna clave interna.
    foreach (['organization_id', 'room_id', 'interno@example.test'] as $secret) {
        expect($emitted)->not->toContain($secret);
    }
});

it('CA-035-30: texts that look like markup are kept as plain text for the page to escape', function (): void {
    $event = ppEvent();
    $park = seatsPlace($event, true, ['name' => '<b>Parque</b>', 'address' => '<script>alert(1)</script>']);
    $activity = ppActivity($event, ['room_id' => $park->id, 'meeting_point' => '<img src=x onerror=alert(2)>', 'transport_info' => '<a href="javascript:alert(3)">bus</a>']);

    [, $detail] = seatsProps($event, $activity);

    expect($detail['place']['name'])->toBe('<b>Parque</b>')
        ->and($detail['place']['address'])->toBe('<script>alert(1)</script>')
        ->and($detail['meetingPoint'])->toBe('<img src=x onerror=alert(2)>')
        ->and($detail['transport'])->toBe('<a href="javascript:alert(3)">bus</a>');
});

it('CA-035-29: the detail does not trigger lazy loading and its queries do not grow with the contacts', function (): void {
    $event = ppEvent();
    $park = seatsPlace($event, true);
    $few = ppActivity($event, ['title' => 'Pocos', 'room_id' => $park->id]);
    seatsContact($few, 1, ['is_public' => true]);
    $many = ppActivity($event, ['title' => 'Muchos', 'room_id' => $park->id]);
    foreach (range(1, 5) as $position) {
        seatsContact($many, $position, ['name' => "Persona {$position}", 'is_public' => true]);
    }

    $count = function (EventActivity $activity) use ($event): int {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        seatsProps($event, $activity);

        return $queries;
    };

    expect($count($many))->toBe($count($few));
});

it('CA-035-25: the activity page sends the seats, place and public contacts, and the program only the badge', function (): void {
    $event = ppEvent();
    $slug = $event->organization->slug;
    $park = seatsPlace($event, true, ['name' => 'Parque Lineal', 'address' => 'Entrada norte']);
    $activity = ppActivity($event, ['title' => 'Taller', 'room_id' => $park->id, 'quota' => 30, 'meeting_point' => 'Quiosco']);
    seatsContact($activity, 1, ['name' => 'Ana', 'is_public' => true]);

    $this->get("/{$slug}/events/{$event->slug}/activities/{$activity->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->component('Events/Activity')
            ->where('activity.seats', 30)
            ->where('activity.place.name', 'Parque Lineal')
            ->where('activity.place.isExternal', true)
            ->where('activity.meetingPoint', 'Quiosco')
            ->where('activity.contacts.0.name', 'Ana'));

    $this->get("/{$slug}/events/{$event->slug}/program")
        ->assertInertia(fn (Assert $page) => $page
            ->where('activities.data.0.limitedSeats', true)
            ->missing('activities.data.0.seats')
            ->missing('activities.data.0.place'));
});

it('CA-035-28: a public contact left on an activity whose place is a room or missing is not published', function (): void {
    $event = ppEvent();
    $room = seatsPlace($event, false, ['name' => 'Sala']);
    $inRoom = ppActivity($event, ['room_id' => $room->id]);
    $nowhere = ppActivity($event, ['title' => 'Sin lugar']);
    seatsContact($inRoom, 1, ['name' => 'Residuo en sala', 'is_public' => true]);
    seatsContact($nowhere, 1, ['name' => 'Residuo sin lugar', 'is_public' => true]);

    expect(seatsProps($event, $inRoom)[1]['contacts'])->toBe([])
        ->and(seatsProps($event, $nowhere)[1]['contacts'])->toBe([]);
});
