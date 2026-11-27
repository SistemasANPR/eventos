<?php

declare(strict_types=1);

namespace App\Support\Program\Agenda;

use App\Enums\RoomKind;
use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Room;
use App\Models\Speaker;
use App\Support\Program\DetectScheduleConflicts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Arma la agenda de lectura de un día y los choques de un evento (plan 031, F4; DA-5 a DA-8; plan 035, DA-9). Solo
 * lectura y en memoria: una consulta de salas, una de actividades y una de ponentes, sin importar cuántas actividades
 * haya (CA-031-29). Un error de base se propaga: nunca devuelve una agenda vacía ante un fallo (CA-031-25).
 *
 * Fecha y hora son LOCALES del evento, sin zona. Cuentan todas las actividades no eliminadas, publicadas o no y ocultas
 * por reversa (DA-6). Se llama dentro de la organización del evento (el filtro de organización del modelo lo exige).
 */
final class AgendaDayBuilder implements BuildsAgenda
{
    private const STEP = 15;

    private const HOUR = 60;

    private const DAY = 1440;

    private const EMPTY_DAY_START = 8 * 60;

    private const EMPTY_DAY_END = 18 * 60;

    /** Tope de días del evento que se recorren, por si una fecha de fin absurda llegara a la base. */
    private const MAX_EVENT_DAYS = 366;

    public function day(Event $event, ?string $date = null): AgendaDay
    {
        [$rooms, $activities] = $this->load($event);
        $scheduled = $this->scheduled($activities);
        $days = $this->days($event, $scheduled);
        $selected = $this->selectedDay($days, $scheduled, $date);
        $clashes = $this->clashesOf($scheduled, $rooms);

        $cells = $this->cellsOfDay($scheduled, $rooms, $selected, $clashes);
        [$start, $end] = $this->range($cells);

        return new AgendaDay(
            date: $selected,
            days: $days,
            startMinute: $start,
            endMinute: $end,
            columns: $this->columns($rooms, $cells),
            pending: $this->pending($activities),
            stepMinutes: self::STEP,
        );
    }

    public function clashes(Event $event): array
    {
        [$rooms, $activities] = $this->load($event);

        return $this->clashesOf($this->scheduled($activities), $rooms);
    }

    /**
     * Las salas del evento (por posición) y todas sus actividades con sus ponentes, en el orden asignado.
     *
     * @return array{0: Collection<int, Room>, 1: Collection<int, EventActivity>}
     */
    private function load(Event $event): array
    {
        $rooms = Room::query()->where('event_id', $event->id)->orderBy('position')->orderBy('name')->get();

        $activities = EventActivity::query()
            ->where('event_id', $event->id)
            ->with('speakers')
            ->orderBy('starts_on')
            ->orderBy('starts_at')
            ->orderBy('title')
            ->orderBy('id')
            ->get();

        return [$rooms, $activities];
    }

    /**
     * Las actividades con fecha y hora completas.
     *
     * @param  Collection<int, EventActivity>  $activities
     * @return Collection<int, EventActivity>
     */
    private function scheduled(Collection $activities): Collection
    {
        return $activities->filter(static fn (EventActivity $activity): bool => $activity->starts_on !== null
            && $activity->ends_on !== null
            && $activity->starts_at !== null
            && $activity->ends_at !== null)->values();
    }

    /**
     * Los días del selector: los locales del evento y los de sus actividades programadas, ordenados y sin repetir.
     *
     * @param  Collection<int, EventActivity>  $scheduled
     * @return list<string>
     */
    private function days(Event $event, Collection $scheduled): array
    {
        $days = $this->eventDays($event);

        foreach ($scheduled as $activity) {
            $days[] = $activity->starts_on?->toDateString() ?? '';
        }

        $days = array_values(array_unique(array_filter($days, static fn (string $day): bool => $day !== '')));
        sort($days);

        return $days;
    }

    /** @return list<string> */
    private function eventDays(Event $event): array
    {
        $start = CarbonImmutable::instance($event->starts_at)->setTimezone($event->timezone)->startOfDay();
        $end = CarbonImmutable::instance($event->ends_at)->setTimezone($event->timezone)->startOfDay();

        if ($end < $start) {
            $end = $start;
        }

        $days = [];

        for ($day = $start; $day <= $end && count($days) < self::MAX_EVENT_DAYS; $day = $day->addDay()) {
            $days[] = $day->toDateString();
        }

        return $days;
    }

    /**
     * El día pedido si está en el selector; si no, el primero con actividades programadas o, sin ellas, el primero del evento.
     *
     * @param  list<string>  $days
     * @param  Collection<int, EventActivity>  $scheduled
     */
    private function selectedDay(array $days, Collection $scheduled, ?string $date): string
    {
        if ($date !== null && in_array($date, $days, true)) {
            return $date;
        }

        $withActivities = $scheduled->map(static fn (EventActivity $activity): string => $activity->starts_on?->toDateString() ?? '')->filter()->sort()->first();

        return is_string($withActivities) ? $withActivities : ($days[0] ?? '');
    }

    /**
     * Las actividades que empiezan el día mostrado, ya como datos de celda; los carriles se reparten por columna.
     *
     * @param  Collection<int, EventActivity>  $scheduled
     * @param  Collection<int, Room>  $rooms
     * @param  array<string, list<AgendaClash>>  $clashes
     * @return array<string, list<AgendaCell>> celdas por clave de columna
     */
    private function cellsOfDay(Collection $scheduled, Collection $rooms, string $day, array $clashes): array
    {
        $roomsById = $rooms->keyBy('id');
        $byColumn = [];

        foreach ($scheduled as $activity) {
            if ($activity->starts_on?->toDateString() !== $day) {
                continue;
            }

            $room = $activity->room_id === null ? null : $roomsById->get($activity->room_id);
            $key = match (true) {
                $room === null => 'none',
                $room->kind === RoomKind::External => 'external',
                default => $room->id,
            };

            $byColumn[$key][] = $this->cellData($activity, $day, $room, $clashes);
        }

        return array_map($this->withLanes(...), $byColumn);
    }

    /**
     * @param  array<string, list<AgendaClash>>  $clashes
     * @return array{start: int, end: int, activity: EventActivity, day: string, room: ?Room, clashes: list<AgendaClash>}
     */
    private function cellData(EventActivity $activity, string $day, ?Room $room, array $clashes): array
    {
        $start = $this->minutes((string) $activity->starts_at);
        $endsToday = $activity->ends_on?->toDateString() === $day;
        $end = $endsToday ? min(self::DAY, max($start, $this->minutes((string) $activity->ends_at))) : self::DAY;

        return ['start' => $start, 'end' => $end, 'activity' => $activity, 'day' => $day, 'room' => $room, 'clashes' => $clashes[$activity->id] ?? []];
    }

    /**
     * Reparte el ancho de la columna entre las celdas que se cruzan: carril libre más bajo, y `lanes` es el máximo del
     * grupo de cruces conectados. Terminar cuando otra empieza no cruza.
     *
     * @param  list<array{start: int, end: int, activity: EventActivity, day: string, room: ?Room, clashes: list<AgendaClash>}>  $items
     * @return list<AgendaCell>
     */
    private function withLanes(array $items): array
    {
        usort($items, static fn (array $a, array $b): int => [$a['start'], $b['end'], $a['activity']->title, $a['activity']->id]
            <=> [$b['start'], $a['end'], $b['activity']->title, $b['activity']->id]);

        /** @var list<list<array{item: array{start: int, end: int, activity: EventActivity, day: string, room: ?Room, clashes: list<AgendaClash>}, lane: int, start: int, end: int}>> $groups */
        $groups = [];
        $groupEnd = 0;

        foreach ($items as $item) {
            // Para repartir el ancho una celda ocupa al menos un paso, aunque dure cero minutos.
            $effectiveEnd = max($item['end'], $item['start'] + self::STEP);

            if ($groups === [] || $item['start'] >= $groupEnd) {
                $groups[] = [];
                $groupEnd = $effectiveEnd;
            }

            $current = array_key_last($groups);
            $taken = [];

            foreach ($groups[$current] as $member) {
                if (DetectScheduleConflicts::overlap($item['start'], $effectiveEnd, $member['start'], $member['end'])) {
                    $taken[$member['lane']] = true;
                }
            }

            $lane = 0;

            while (isset($taken[$lane])) {
                $lane++;
            }

            $groups[$current][] = ['item' => $item, 'lane' => $lane, 'start' => $item['start'], 'end' => $effectiveEnd];
            $groupEnd = max($groupEnd, $effectiveEnd);
        }

        $cells = [];

        foreach ($groups as $group) {
            $lanes = max(array_column($group, 'lane')) + 1;

            foreach ($group as $member) {
                $cells[] = $this->cell($member['item'], $member['lane'], $lanes);
            }
        }

        return $cells;
    }

    /** @param  array{start: int, end: int, activity: EventActivity, day: string, room: ?Room, clashes: list<AgendaClash>}  $item */
    private function cell(array $item, int $lane, int $lanes): AgendaCell
    {
        $activity = $item['activity'];

        return new AgendaCell(
            activityId: $activity->id,
            title: $activity->title,
            startMinute: $item['start'],
            endMinute: $item['end'],
            lane: $lane,
            lanes: $lanes,
            continuesNextDay: $activity->ends_on?->toDateString() !== $item['day'],
            isMultiDay: $activity->ends_on?->toDateString() !== $activity->starts_on?->toDateString(),
            isPublished: $activity->is_published,
            isHiddenByReversal: $activity->hidden_by_reversal_at !== null,
            placeName: $item['room']?->kind === RoomKind::External ? $item['room']->name : null,
            quota: $activity->quota,
            speakers: $this->speakerNames($activity),
            clashes: $item['clashes'],
        );
    }

    /**
     * El rango visible: una hora antes de la primera celda a una hora después de la última, en pasos de 15 minutos y sin
     * salir del día; con el día vacío, de 08:00 a 18:00.
     *
     * @param  array<string, list<AgendaCell>>  $cells
     * @return array{0: int, 1: int}
     */
    private function range(array $cells): array
    {
        $all = array_merge(...array_values($cells));

        if ($all === []) {
            return [self::EMPTY_DAY_START, self::EMPTY_DAY_END];
        }

        $first = min(array_map(static fn (AgendaCell $cell): int => $cell->startMinute, $all));
        $last = max(array_map(static fn (AgendaCell $cell): int => $cell->endMinute, $all));

        return [
            max(0, intdiv($first - self::HOUR, self::STEP) * self::STEP),
            min(self::DAY, (int) (ceil(($last + self::HOUR) / self::STEP) * self::STEP)),
        ];
    }

    /**
     * Una columna por sala en su orden (aunque ese día no tenga actividades), «Fuera de sede» solo si el evento tiene un
     * lugar externo y «Sin lugar» siempre al final.
     *
     * @param  Collection<int, Room>  $rooms
     * @param  array<string, list<AgendaCell>>  $cells
     * @return list<AgendaColumn>
     */
    private function columns(Collection $rooms, array $cells): array
    {
        $columns = [];

        foreach ($rooms as $room) {
            if ($room->kind === RoomKind::Room) {
                $columns[] = new AgendaColumn($room->id, AgendaColumnKind::Room, $room->name, $room->capacity, $cells[$room->id] ?? []);
            }
        }

        if ($rooms->contains(static fn (Room $room): bool => $room->kind === RoomKind::External)) {
            $columns[] = new AgendaColumn('external', AgendaColumnKind::External, null, null, $cells['external'] ?? []);
        }

        $columns[] = new AgendaColumn('none', AgendaColumnKind::None, null, null, $cells['none'] ?? []);

        return $columns;
    }

    /**
     * Las actividades sin programar, para el panel de pendientes (no dependen del día).
     *
     * @param  Collection<int, EventActivity>  $activities
     * @return list<AgendaPending>
     */
    private function pending(Collection $activities): array
    {
        return $activities
            ->filter(static fn (EventActivity $activity): bool => $activity->starts_on === null)
            ->sortBy([['title', 'asc'], ['id', 'asc']])
            ->map(fn (EventActivity $activity): AgendaPending => new AgendaPending($activity->id, $activity->title, $activity->is_published, $this->speakerNames($activity)))
            ->values()
            ->all();
    }

    /**
     * Los choques de las actividades programadas, por id: sala ocupada (solo salas; los lugares externos admiten
     * actividades simultáneas) y ponente repetido (en cualquier lugar). Mismo solape medio abierto que la consulta.
     *
     * @param  Collection<int, EventActivity>  $scheduled
     * @param  Collection<int, Room>  $rooms
     * @return array<string, list<AgendaClash>>
     */
    private function clashesOf(Collection $scheduled, Collection $rooms): array
    {
        $items = $scheduled->map(fn (EventActivity $activity): array => [
            'activity' => $activity,
            'start' => DetectScheduleConflicts::absoluteMinute((string) $activity->starts_on?->toDateString(), (string) $activity->starts_at),
            'end' => DetectScheduleConflicts::absoluteMinute((string) $activity->ends_on?->toDateString(), (string) $activity->ends_at),
            'speakers' => $activity->speakers->mapWithKeys(static fn (Speaker $speaker): array => [$speaker->id => $speaker->full_name])->all(),
        ])->values()->all();

        $roomKinds = $rooms->mapWithKeys(static fn (Room $room): array => [$room->id => $room->kind])->all();
        $clashes = [];
        $count = count($items);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                [$a, $b] = [$items[$i], $items[$j]];

                if (! DetectScheduleConflicts::overlap($a['start'], $a['end'], $b['start'], $b['end'])) {
                    continue;
                }

                $roomId = $a['activity']->room_id;

                if ($roomId !== null && $roomId === $b['activity']->room_id && ($roomKinds[$roomId] ?? null) === RoomKind::Room) {
                    $clashes[$a['activity']->id][] = new AgendaClash(AgendaClashType::Room, $b['activity']->id, $b['activity']->title);
                    $clashes[$b['activity']->id][] = new AgendaClash(AgendaClashType::Room, $a['activity']->id, $a['activity']->title);
                }

                $shared = array_intersect_key($a['speakers'], $b['speakers']);
                asort($shared);

                foreach ($shared as $name) {
                    $clashes[$a['activity']->id][] = new AgendaClash(AgendaClashType::Speaker, $b['activity']->id, $b['activity']->title, $name);
                    $clashes[$b['activity']->id][] = new AgendaClash(AgendaClashType::Speaker, $a['activity']->id, $a['activity']->title, $name);
                }
            }
        }

        return $clashes;
    }

    /** @return list<string> */
    private function speakerNames(EventActivity $activity): array
    {
        return $activity->speakers->map(static fn (Speaker $speaker): string => $speaker->full_name)->values()->all();
    }

    /** Minutos desde las 00:00 de una hora `H:i` o `H:i:s`. */
    private function minutes(string $time): int
    {
        return (int) substr($time, 0, 2) * self::HOUR + (int) substr($time, 3, 2);
    }
}
