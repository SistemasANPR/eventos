<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Room;
use App\Models\Speaker;
use App\Support\Program\Agenda\AgendaColumn;
use App\Support\Program\Agenda\AgendaColumnKind;
use App\Support\Program\Agenda\AgendaDay;
use App\Support\Program\Agenda\BuildsAgenda;
use App\Support\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/*
 * Plan 031, F4: la capa de datos de la agenda de lectura (`AgendaDayBuilder`). Fecha y hora son LOCALES del evento
 * (sin zona). El evento de prueba va del 2027-03-10 al 2027-03-12 (hora de México).
 */

afterEach(function (): void {
    CurrentOrganization::forget();
});

/**
 * @param  Closure(): mixed  $callback
 */
function agRun(Event $event, Closure $callback): mixed
{
    return CurrentOrganization::run($event->organization, $callback);
}

function agRoom(Event $event, string $name, int $position, bool $external = false): Room
{
    return agRun($event, fn (): Room => ($external ? Room::factory()->external() : Room::factory())->create([
        'event_id' => $event->id, 'name' => $name, 'position' => $position,
    ]));
}

/** @param  array<string, mixed>  $attributes */
function agActivity(Event $event, ?Room $room, string $from, string $to, string $title, array $attributes = [], string $day = '2027-03-10', ?string $endDay = null): EventActivity
{
    return agRun($event, fn (): EventActivity => EventActivity::factory()->create([
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

function agSpeaker(Event $event, string $first, string $last): Speaker
{
    return agRun($event, fn (): Speaker => Speaker::factory()->create(['event_id' => $event->id, 'first_name' => $first, 'last_name' => $last]));
}

function agAssign(EventActivity $activity, Speaker $speaker, int $position = 0): void
{
    DB::table('activity_speaker')->insert([
        'activity_id' => $activity->id, 'speaker_id' => $speaker->id,
        'organization_id' => $activity->organization_id, 'event_id' => $activity->event_id, 'position' => $position,
    ]);
}

function agDay(Event $event, ?string $date = null): AgendaDay
{
    return agRun($event, fn (): AgendaDay => app(BuildsAgenda::class)->day($event, $date));
}

/** @return array<string, AgendaColumn> columnas por clave */
function agColumns(AgendaDay $day): array
{
    $columns = [];

    foreach ($day->columns as $column) {
        $columns[$column->key] = $column;
    }

    return $columns;
}

/** @return list<string> títulos de las celdas de una columna, en su orden */
function agTitles(AgendaColumn $column): array
{
    return array_map(static fn ($cell): string => $cell->title, $column->cells);
}

it('CA-031-19: one column per room in position order, then no place, with the activities of the day at their minutes', function (): void {
    $event = Event::factory()->create();
    $second = agRoom($event, 'Sala B', 2);
    $first = agRoom($event, 'Auditorio', 1);
    agActivity($event, $first, '10:00:00', '11:30:00', 'Apertura');
    agActivity($event, $second, '12:00:00', '13:00:00', 'Taller');
    agActivity($event, null, '15:15:00', '16:00:00', 'Plenaria');

    $day = agDay($event, '2027-03-10');

    expect(array_map(static fn (AgendaColumn $c): string => $c->key, $day->columns))->toBe([$first->id, $second->id, 'none'])
        ->and(array_map(static fn (AgendaColumn $c): ?string => $c->label, $day->columns))->toBe(['Auditorio', 'Sala B', null])
        ->and(array_map(static fn (AgendaColumn $c): AgendaColumnKind => $c->kind, $day->columns))->toBe([AgendaColumnKind::Room, AgendaColumnKind::Room, AgendaColumnKind::None]);

    $columns = agColumns($day);
    $apertura = $columns[$first->id]->cells[0];

    expect($apertura->title)->toBe('Apertura')
        ->and([$apertura->startMinute, $apertura->endMinute])->toBe([600, 690])
        ->and(agTitles($columns[$second->id]))->toBe(['Taller'])
        ->and(agTitles($columns['none']))->toBe(['Plenaria'])
        ->and($columns['none']->cells[0]->startMinute)->toBe(915);
});

it('CA-031-19: a room without activities that day still has its column', function (): void {
    $event = Event::factory()->create();
    $used = agRoom($event, 'Usada', 1);
    $empty = agRoom($event, 'Vacia', 2);
    agActivity($event, $used, '10:00:00', '11:00:00', 'Algo');

    $columns = agColumns(agDay($event, '2027-03-10'));

    expect(array_keys($columns))->toBe([$used->id, $empty->id, 'none'])
        ->and($columns[$empty->id]->cells)->toBe([]);
});

it('CA-031-19: the column for external places appears only when the event has one, before no place, and gathers all of them', function (): void {
    $event = Event::factory()->create();
    $room = agRoom($event, 'Sala', 1);

    expect(array_keys(agColumns(agDay($event, '2027-03-10'))))->toBe([$room->id, 'none']);

    $park = agRoom($event, 'Parque Lineal', 2, external: true);
    $plaza = agRoom($event, 'Plaza', 3, external: true);
    agActivity($event, $park, '09:00:00', '11:00:00', 'Taller en el parque', ['quota' => 25]);
    agActivity($event, $plaza, '09:30:00', '10:30:00', 'Recorrido');

    $columns = agColumns(agDay($event, '2027-03-10'));

    expect(array_keys($columns))->toBe([$room->id, 'external', 'none'])
        ->and($columns['external']->kind)->toBe(AgendaColumnKind::External)
        ->and($columns['external']->label)->toBeNull()
        ->and(agTitles($columns['external']))->toBe(['Taller en el parque', 'Recorrido'])
        ->and($columns['external']->cells[0]->placeName)->toBe('Parque Lineal')
        ->and($columns['external']->cells[0]->quota)->toBe(25)
        ->and($columns['external']->cells[1]->placeName)->toBe('Plaza');
});

it('CA-031-19: the day selector lists the local days of the event and the days of its activities, sorted and without repeats', function (): void {
    $event = Event::factory()->create();
    agActivity($event, null, '10:00:00', '11:00:00', 'Dentro');
    agActivity($event, null, '10:00:00', '11:00:00', 'Otra dentro');
    agActivity($event, null, '10:00:00', '11:00:00', 'Antes', day: '2027-03-08');
    agActivity($event, null, '10:00:00', '11:00:00', 'Despues', day: '2027-03-15');

    expect(agDay($event, '2027-03-10')->days)->toBe(['2027-03-08', '2027-03-10', '2027-03-11', '2027-03-12', '2027-03-15']);
});

it('CA-031-19: the event days are the local days in the time zone of the event, not the UTC ones', function (): void {
    $event = Event::factory()->create(['starts_at' => '2027-03-11 02:00:00', 'ends_at' => '2027-03-11 23:30:00', 'timezone' => 'America/Mexico_City']);

    // 02:00 UTC es el 10 a las 20:00 en México; 23:30 UTC es el 11 a las 17:30.
    expect(agDay($event)->days)->toBe(['2027-03-10', '2027-03-11']);
});

it('CA-031-19: without a date, or with a day outside the selector, it shows the first day with scheduled activities', function (): void {
    $event = Event::factory()->create();
    agActivity($event, null, '10:00:00', '11:00:00', 'El 11', day: '2027-03-11');
    agActivity($event, null, '10:00:00', '11:00:00', 'El 12', day: '2027-03-12');

    expect(agDay($event)->date)->toBe('2027-03-11')
        ->and(agDay($event, '2030-01-01')->date)->toBe('2027-03-11')
        ->and(agDay($event, 'no-es-fecha')->date)->toBe('2027-03-11')
        ->and(agDay($event, '2027-03-12')->date)->toBe('2027-03-12');
});

it('CA-031-19: with no scheduled activities it shows the first day of the event', function (): void {
    $event = Event::factory()->create();

    expect(agDay($event)->date)->toBe('2027-03-10');
});

it('CA-031-19: an activity appears only under the day it starts', function (): void {
    $event = Event::factory()->create();
    agActivity($event, null, '10:00:00', '11:00:00', 'Del diez');
    agActivity($event, null, '10:00:00', '11:00:00', 'Del once', day: '2027-03-11');

    expect(agTitles(agColumns(agDay($event, '2027-03-10'))['none']))->toBe(['Del diez'])
        ->and(agTitles(agColumns(agDay($event, '2027-03-11'))['none']))->toBe(['Del once']);
});

it('CA-031-19: the visible range goes from one hour before the first activity to one hour after the last, in steps of 15 minutes', function (): void {
    $event = Event::factory()->create();
    agActivity($event, null, '10:10:00', '11:00:00', 'Primera');
    agActivity($event, null, '14:00:00', '15:20:00', 'Ultima');

    $day = agDay($event, '2027-03-10');

    // 10:10 - 1 h = 09:10, hacia abajo 09:00; 15:20 + 1 h = 16:20, hacia arriba 16:30.
    expect([$day->startMinute, $day->endMinute, $day->stepMinutes])->toBe([540, 990, 15]);
});

it('CA-031-19: an empty day shows from 08:00 to 18:00', function (): void {
    $event = Event::factory()->create();

    $day = agDay($event, '2027-03-11');

    expect([$day->startMinute, $day->endMinute])->toBe([480, 1080]);
});

it('CA-031-19: the visible range never leaves the day', function (): void {
    $event = Event::factory()->create();
    agActivity($event, null, '00:30:00', '01:00:00', 'Madrugada');
    agActivity($event, null, '23:00:00', '23:50:00', 'Noche');

    $day = agDay($event, '2027-03-10');

    expect([$day->startMinute, $day->endMinute])->toBe([0, 1440]);
});

it('CA-031-23: an activity that crosses midnight, or lasts several days, shows under its start day with the indicator and is cut at the end of the day', function (): void {
    $event = Event::factory()->create();
    agActivity($event, null, '22:00:00', '02:00:00', 'Fiesta', day: '2027-03-10', endDay: '2027-03-11');
    agActivity($event, null, '10:00:00', '11:00:00', 'Normal');

    $first = agColumns(agDay($event, '2027-03-10'))['none']->cells;
    $second = agColumns(agDay($event, '2027-03-11'))['none']->cells;
    $fiesta = collect($first)->firstWhere('title', 'Fiesta');
    $normal = collect($first)->firstWhere('title', 'Normal');

    expect([$fiesta->startMinute, $fiesta->endMinute, $fiesta->continuesNextDay, $fiesta->isMultiDay])->toBe([1320, 1440, true, true])
        ->and([$normal->continuesNextDay, $normal->isMultiDay])->toBe([false, false])
        ->and($second)->toBe([]);
});

it('CA-031-23: an activity of several days is cut at the end of its first day', function (): void {
    $event = Event::factory()->create();
    agActivity($event, null, '09:00:00', '17:00:00', 'Jornada larga', day: '2027-03-10', endDay: '2027-03-12');

    $cell = agColumns(agDay($event, '2027-03-10'))['none']->cells[0];

    expect([$cell->startMinute, $cell->endMinute, $cell->continuesNextDay, $cell->isMultiDay])->toBe([540, 1440, true, true]);
});

it('CA-031-19: unpublished and hidden-by-reversal activities count and carry their flags, and deleted ones do not', function (): void {
    $event = Event::factory()->create();
    agActivity($event, null, '09:00:00', '10:00:00', 'Publicada');
    agActivity($event, null, '10:00:00', '11:00:00', 'Borrador', ['is_published' => false]);
    agActivity($event, null, '11:00:00', '12:00:00', 'Oculta', ['is_published' => false, 'hidden_by_reversal_at' => now()]);
    $deleted = agActivity($event, null, '12:00:00', '13:00:00', 'Eliminada');
    agRun($event, fn () => $deleted->delete());

    $cells = collect(agColumns(agDay($event, '2027-03-10'))['none']->cells)->keyBy('title');

    expect($cells->keys()->all())->toBe(['Publicada', 'Borrador', 'Oculta'])
        ->and([$cells['Publicada']->isPublished, $cells['Publicada']->isHiddenByReversal])->toBe([true, false])
        ->and([$cells['Borrador']->isPublished, $cells['Borrador']->isHiddenByReversal])->toBe([false, false])
        ->and([$cells['Oculta']->isPublished, $cells['Oculta']->isHiddenByReversal])->toBe([false, true]);
});

it('CA-031-19: the speakers of a cell come in the assigned order with their full name', function (): void {
    $event = Event::factory()->create();
    $activity = agActivity($event, null, '10:00:00', '11:00:00', 'Panel');
    agAssign($activity, agSpeaker($event, 'Zoe', 'Vega'), 1);
    agAssign($activity, agSpeaker($event, 'Ana', 'Lopez'), 0);

    $cell = agColumns(agDay($event, '2027-03-10'))['none']->cells[0];

    expect($cell->speakers)->toBe(['Ana Lopez', 'Zoe Vega']);
});

it('CA-031-19: cells that overlap in the same column share its width in lanes, and those that only touch do not', function (): void {
    $event = Event::factory()->create();
    $park = agRoom($event, 'Parque', 1, external: true);
    agActivity($event, $park, '10:00:00', '12:00:00', 'Larga');
    agActivity($event, $park, '10:00:00', '11:00:00', 'Corta uno');
    agActivity($event, $park, '11:00:00', '12:00:00', 'Corta dos');
    agActivity($event, $park, '13:00:00', '14:00:00', 'Sola');

    $cells = collect(agColumns(agDay($event, '2027-03-10'))['external']->cells)->keyBy('title');

    expect([$cells['Larga']->lane, $cells['Larga']->lanes])->toBe([0, 2])
        ->and([$cells['Corta uno']->lane, $cells['Corta uno']->lanes])->toBe([1, 2])
        ->and([$cells['Corta dos']->lane, $cells['Corta dos']->lanes])->toBe([1, 2])
        ->and([$cells['Sola']->lane, $cells['Sola']->lanes])->toBe([0, 1]);
});

it('CA-031-19: activities that end when the next starts are not placed side by side', function (): void {
    $event = Event::factory()->create();
    $room = agRoom($event, 'Sala', 1);
    agActivity($event, $room, '10:00:00', '11:00:00', 'Una');
    agActivity($event, $room, '11:00:00', '12:00:00', 'Dos');

    $cells = agColumns(agDay($event, '2027-03-10'))[$room->id]->cells;

    expect(array_map(static fn ($cell): array => [$cell->lane, $cell->lanes], $cells))->toBe([[0, 1], [0, 1]]);
});

it('CA-031-19: the unscheduled activities go to the pending list with their speakers, whatever the day, and not to the grid', function (): void {
    $event = Event::factory()->create();
    $pending = agRun($event, fn (): EventActivity => EventActivity::factory()->unscheduled()->create(['event_id' => $event->id, 'title' => 'Por programar', 'is_published' => false]));
    agAssign($pending, agSpeaker($event, 'Ada', 'Lovelace'));
    agActivity($event, null, '10:00:00', '11:00:00', 'Programada');

    foreach (['2027-03-10', '2027-03-11'] as $date) {
        $day = agDay($event, $date);

        expect(array_map(static fn ($item): string => $item->title, $day->pending))->toBe(['Por programar'])
            ->and($day->pending[0]->activityId)->toBe($pending->id)
            ->and($day->pending[0]->isPublished)->toBeFalse()
            ->and($day->pending[0]->speakers)->toBe(['Ada Lovelace']);
    }

    expect(agTitles(agColumns(agDay($event, '2027-03-10'))['none']))->toBe(['Programada']);
});

it('CA-031-19: it never shows activities of another event or another organization', function (): void {
    $event = Event::factory()->create();
    $sameOrganization = Event::factory()->create(['organization_id' => $event->organization_id]);
    $foreign = Event::factory()->create();
    agActivity($event, null, '10:00:00', '11:00:00', 'Mia');
    agActivity($sameOrganization, null, '10:00:00', '11:00:00', 'De otro evento');
    agActivity($foreign, null, '10:00:00', '11:00:00', 'De otra organizacion');
    agRoom($sameOrganization, 'Sala ajena', 1);

    $day = agDay($event, '2027-03-10');

    expect(agTitles(agColumns($day)['none']))->toBe(['Mia'])
        ->and(array_keys(agColumns($day)))->toBe(['none']);
});

it('CA-031-25: an event with no rooms and no activities has the no-place column and no pending list', function (): void {
    $event = Event::factory()->create();

    $day = agDay($event);

    expect(array_map(static fn (AgendaColumn $c): string => $c->key, $day->columns))->toBe(['none'])
        ->and($day->columns[0]->cells)->toBe([])
        ->and($day->pending)->toBe([])
        ->and($day->days)->toBe(['2027-03-10', '2027-03-11', '2027-03-12']);
});

it('CA-031-25: a database failure is raised and never shown as an empty agenda', function (): void {
    $event = Event::factory()->create();
    agActivity($event, null, '10:00:00', '11:00:00', 'Algo');
    Schema::drop('activity_speaker');

    expect(fn () => agDay($event, '2027-03-10'))->toThrow(QueryException::class);
});

it('CA-031-29: the number of queries does not grow with the number of activities', function (): void {
    $measure = static function (int $activities): int {
        $event = Event::factory()->create();
        $rooms = [];

        foreach (range(1, 8) as $n) {
            $rooms[] = agRoom($event, "Lugar {$n}", $n, external: $n === 8);
        }

        $speakers = array_map(static fn (int $n): Speaker => agSpeaker($event, "Nombre{$n}", "Apellido{$n}"), range(1, 20));

        foreach (range(1, $activities) as $n) {
            $activity = agActivity($event, $rooms[$n % 8], sprintf('%02d:00:00', 8 + $n % 10), sprintf('%02d:30:00', 8 + $n % 10), "Actividad {$n}", day: '2027-03-1'.(0 + $n % 3));
            agAssign($activity, $speakers[$n % 20], 0);
            agAssign($activity, $speakers[($n + 7) % 20], 1);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        agDay($event, '2027-03-10');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $few = $measure(10);
    $many = $measure(50);

    expect($many)->toBe($few)->and($many)->toBeLessThanOrEqual(6);
});
