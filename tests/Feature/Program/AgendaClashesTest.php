<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Room;
use App\Models\Speaker;
use App\Support\Program\Agenda\AgendaClash;
use App\Support\Program\Agenda\AgendaClashType;
use App\Support\Program\Agenda\BuildsAgenda;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * Plan 031, F4: los choques que marcan la agenda y la lista (CA-031-20). Misma definición de solape de
 * `DetectScheduleConflicts` (DA-5): intervalos medio abiertos sobre fecha y hora locales; una sala física no admite
 * dos actividades a la vez (los lugares externos sí); un ponente en dos actividades que se cruzan es un aviso.
 */

afterEach(function (): void {
    CurrentOrganization::forget();
});

function clRoom(Event $event, string $name, int $position = 1, bool $external = false): Room
{
    return CurrentOrganization::run($event->organization, fn (): Room => ($external ? Room::factory()->external() : Room::factory())->create([
        'event_id' => $event->id, 'name' => $name, 'position' => $position,
    ]));
}

/** @param  array<string, mixed>  $attributes */
function clActivity(Event $event, ?Room $room, string $from, string $to, string $title, array $attributes = [], string $day = '2027-03-10', ?string $endDay = null): EventActivity
{
    return CurrentOrganization::run($event->organization, fn (): EventActivity => EventActivity::factory()->create([
        'event_id' => $event->id, 'room_id' => $room?->id, 'title' => $title,
        'starts_on' => $day, 'ends_on' => $endDay ?? $day, 'starts_at' => $from, 'ends_at' => $to,
        ...$attributes,
    ]));
}

function clSpeaker(Event $event, string $first = 'Ada', string $last = 'Lovelace'): Speaker
{
    return CurrentOrganization::run($event->organization, fn (): Speaker => Speaker::factory()->create(['event_id' => $event->id, 'first_name' => $first, 'last_name' => $last]));
}

function clAssign(EventActivity $activity, Speaker $speaker): void
{
    DB::table('activity_speaker')->insert([
        'activity_id' => $activity->id, 'speaker_id' => $speaker->id,
        'organization_id' => $activity->organization_id, 'event_id' => $activity->event_id, 'position' => 0,
    ]);
}

/** @return array<string, list<AgendaClash>> */
function clClashes(Event $event): array
{
    return CurrentOrganization::run($event->organization, fn (): array => app(BuildsAgenda::class)->clashes($event));
}

/**
 * @param  array<string, list<AgendaClash>>  $clashes
 * @return list<string> «tipo:título contrario» de cada choque de la actividad
 */
function clSummary(array $clashes, EventActivity $activity): array
{
    return array_map(static fn (AgendaClash $c): string => $c->type->value.':'.$c->withTitle, $clashes[$activity->id] ?? []);
}

it('CA-031-20: two overlapping activities in the same room mark each other with the room clash and the other title', function (): void {
    $event = Event::factory()->create();
    $room = clRoom($event, 'Sala A');
    $one = clActivity($event, $room, '10:00:00', '11:00:00', 'Primera');
    $two = clActivity($event, $room, '10:30:00', '11:30:00', 'Segunda');
    $clashes = clClashes($event);

    expect(clSummary($clashes, $one))->toBe(['room:Segunda'])
        ->and(clSummary($clashes, $two))->toBe(['room:Primera'])
        ->and($clashes[$one->id][0]->withActivityId)->toBe($two->id)
        ->and($clashes[$one->id][0]->type)->toBe(AgendaClashType::Room)
        ->and($clashes[$one->id][0]->speaker)->toBeNull();
});

it('CA-031-20: activities that only touch, in another room or without room do not clash by room', function (): void {
    $event = Event::factory()->create();
    $room = clRoom($event, 'Sala A', 1);
    $other = clRoom($event, 'Sala B', 2);
    clActivity($event, $room, '10:00:00', '11:00:00', 'Una');
    clActivity($event, $room, '11:00:00', '12:00:00', 'Dos');
    clActivity($event, $other, '10:00:00', '12:00:00', 'En otra sala');
    clActivity($event, null, '10:00:00', '12:00:00', 'Sin lugar uno');
    clActivity($event, null, '10:00:00', '12:00:00', 'Sin lugar dos');

    expect(clClashes($event))->toBe([]);
});

it('CA-031-20: external places never clash with each other by room', function (): void {
    $event = Event::factory()->create();
    $park = clRoom($event, 'Parque', 1, external: true);
    clActivity($event, $park, '10:00:00', '12:00:00', 'Taller uno');
    clActivity($event, $park, '10:30:00', '11:30:00', 'Taller dos');

    expect(clClashes($event))->toBe([]);
});

it('CA-031-20: a speaker in two overlapping activities, in any room, marks both with the speaker and the other title', function (): void {
    $event = Event::factory()->create();
    $ada = clSpeaker($event, 'Ada', 'Lovelace');
    $one = clActivity($event, clRoom($event, 'Sala A', 1), '10:00:00', '11:00:00', 'Charla');
    $two = clActivity($event, clRoom($event, 'Sala B', 2), '10:30:00', '11:30:00', 'Taller');
    clAssign($one, $ada);
    clAssign($two, $ada);
    $clashes = clClashes($event);

    expect(clSummary($clashes, $one))->toBe(['speaker:Taller'])
        ->and(clSummary($clashes, $two))->toBe(['speaker:Charla'])
        ->and($clashes[$one->id][0]->speaker)->toBe('Ada Lovelace')
        ->and($clashes[$one->id][0]->withActivityId)->toBe($two->id);
});

it('CA-031-20: the speaker clash also marks activities in external places or without place, and ignores the ones that only touch', function (): void {
    $event = Event::factory()->create();
    $ada = clSpeaker($event);
    $park = clRoom($event, 'Parque', 1, external: true);
    $one = clActivity($event, $park, '10:00:00', '11:00:00', 'En el parque');
    $two = clActivity($event, null, '10:30:00', '11:30:00', 'Sin lugar');
    $three = clActivity($event, null, '11:30:00', '12:30:00', 'Despues');
    foreach ([$one, $two, $three] as $activity) {
        clAssign($activity, $ada);
    }
    $clashes = clClashes($event);

    expect(clSummary($clashes, $one))->toBe(['speaker:Sin lugar'])
        ->and(clSummary($clashes, $two))->toBe(['speaker:En el parque'])
        ->and(array_key_exists($three->id, $clashes))->toBeFalse();
});

it('CA-031-20: an activity can carry both a room clash and a speaker clash, and one entry per speaker', function (): void {
    $event = Event::factory()->create();
    $room = clRoom($event, 'Sala A');
    $ada = clSpeaker($event, 'Ada', 'Lovelace');
    $alan = clSpeaker($event, 'Alan', 'Turing');
    $one = clActivity($event, $room, '10:00:00', '11:00:00', 'Primera');
    $two = clActivity($event, $room, '10:30:00', '11:30:00', 'Segunda');
    foreach ([$one, $two] as $activity) {
        clAssign($activity, $ada);
        clAssign($activity, $alan);
    }
    $summary = clSummary(clClashes($event), $one);
    sort($summary);

    expect($summary)->toBe(['room:Segunda', 'speaker:Segunda', 'speaker:Segunda'])
        ->and(collect(clClashes($event)[$one->id])->where('type', AgendaClashType::Speaker)->pluck('speaker')->sort()->values()->all())->toBe(['Ada Lovelace', 'Alan Turing']);
});

it('CA-031-20: unpublished and hidden activities count for the clash, deleted and unscheduled ones do not', function (): void {
    $event = Event::factory()->create();
    $room = clRoom($event, 'Sala A');
    $base = clActivity($event, $room, '10:00:00', '12:00:00', 'Base');
    clActivity($event, $room, '10:30:00', '11:00:00', 'Borrador', ['is_published' => false]);
    clActivity($event, $room, '11:00:00', '11:30:00', 'Oculta', ['is_published' => false, 'hidden_by_reversal_at' => now()]);
    $deleted = clActivity($event, $room, '10:00:00', '12:00:00', 'Eliminada');
    CurrentOrganization::run($event->organization, fn () => $deleted->delete());
    CurrentOrganization::run($event->organization, fn () => EventActivity::factory()->unscheduled()->create(['event_id' => $event->id, 'title' => 'Sin programar']));
    $summary = clSummary(clClashes($event), $base);
    sort($summary);

    expect($summary)->toBe(['room:Borrador', 'room:Oculta']);
});

it('CA-031-20: the overlap crosses midnight and uses half-open intervals', function (): void {
    $event = Event::factory()->create();
    $room = clRoom($event, 'Sala A');
    $party = clActivity($event, $room, '22:00:00', '02:00:00', 'Fiesta', day: '2027-03-10', endDay: '2027-03-11');
    $early = clActivity($event, $room, '01:00:00', '02:30:00', 'Madrugada', day: '2027-03-11');
    $after = clActivity($event, $room, '02:00:00', '03:00:00', 'Desayuno', day: '2027-03-11');
    $clashes = clClashes($event);

    // La fiesta termina a las 02:00: el desayuno que empieza a las 02:00 no choca con ella, solo con la madrugada.
    expect(clSummary($clashes, $party))->toBe(['room:Madrugada'])
        ->and(clSummary($clashes, $early))->toBe(['room:Fiesta', 'room:Desayuno'])
        ->and(clSummary($clashes, $after))->toBe(['room:Madrugada']);
});

it('CA-031-20: the cells of the day carry the same clashes the list uses', function (): void {
    $event = Event::factory()->create();
    $room = clRoom($event, 'Sala A');
    $one = clActivity($event, $room, '10:00:00', '11:00:00', 'Primera');
    clActivity($event, $room, '10:30:00', '11:30:00', 'Segunda');
    clActivity($event, $room, '14:00:00', '15:00:00', 'Tranquila');

    $day = CurrentOrganization::run($event->organization, fn () => app(BuildsAgenda::class)->day($event, '2027-03-10'));
    $cells = collect($day->columns[0]->cells)->keyBy('title');

    expect(array_map(static fn (AgendaClash $c): string => $c->withTitle, $cells['Primera']->clashes))->toBe(['Segunda'])
        ->and($cells['Primera']->clashes)->toEqual(clClashes($event)[$one->id])
        ->and($cells['Tranquila']->clashes)->toBe([]);
});

it('CA-031-20: the clashes stay inside the event', function (): void {
    $event = Event::factory()->create();
    $other = Event::factory()->create(['organization_id' => $event->organization_id]);
    $foreign = Event::factory()->create();
    $one = clActivity($event, clRoom($event, 'Sala A'), '10:00:00', '11:00:00', 'Mia');
    clActivity($other, clRoom($other, 'Sala A'), '10:00:00', '11:00:00', 'De otro evento');
    clActivity($foreign, clRoom($foreign, 'Sala A'), '10:00:00', '11:00:00', 'De otra organizacion');

    expect(clClashes($event))->toBe([])
        ->and(array_key_exists($one->id, clClashes($event)))->toBeFalse();
});
