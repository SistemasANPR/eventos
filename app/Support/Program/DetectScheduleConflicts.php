<?php

declare(strict_types=1);

namespace App\Support\Program;

use App\Enums\RoomKind;
use App\Models\EventActivity;
use App\Models\Room;
use App\Models\Speaker;
use Illuminate\Database\Eloquent\Builder;

/**
 * Única definición de «se solapan» del programa (plan 031, DA-5). Solo lectura: no escribe nada y deja
 * que un error de base se propague (nunca devuelve «sin choques» ante un fallo).
 *
 * Fecha y hora son locales del evento (sin zona), así que el intervalo de una actividad es
 * `starts_on + starts_at` hasta `ends_on + ends_at`, medio abierto: terminar a las 10:00 y empezar a
 * las 10:00 no choca, y una actividad que cruza la medianoche ocupa también las primeras horas del día
 * siguiente. Cuentan las no publicadas y las ocultas por reversa; las eliminadas no (borrado lógico).
 * Debe llamarse dentro de la organización del evento (el filtro de organización del modelo lo exige).
 */
final class DetectScheduleConflicts
{
    /**
     * La actividad que ya ocupa la sala en ese intervalo, si la hay. Sin sala no hay choque posible.
     *
     * @param  array{starts_on: string, starts_at: string, ends_on: string, ends_at: string}  $slot  fechas `Y-m-d` y horas `H:i:s`
     */
    public static function roomConflict(string $eventId, ?string $roomId, array $slot, ?string $exceptActivityId = null): ?EventActivity
    {
        if ($roomId === null) {
            return null;
        }

        // Un lugar externo admite actividades simultáneas (plan 035, CA-035-16): solo las salas se excluyen entre sí.
        $kind = Room::query()->whereKey($roomId)->value('kind');

        if ($kind instanceof RoomKind && $kind->allowsOverlap()) {
            return null;
        }

        return self::overlapping($eventId, $slot, $exceptActivityId)
            ->where('room_id', $roomId)
            ->orderBy('starts_on')
            ->orderBy('starts_at')
            ->first();
    }

    /**
     * Un aviso por cada ponente de la actividad que está en otra actividad del evento que se cruza
     * con ella (en cualquier sala). Una actividad sin programar no tiene choques.
     *
     * @return list<array{speaker: string, activity: string, activity_id: string}>
     */
    public static function speakerClashes(EventActivity $activity): array
    {
        if (! $activity->isScheduled() || $activity->starts_at === null || $activity->ends_at === null) {
            return [];
        }

        $speakers = $activity->speakers()->get();

        if ($speakers->isEmpty()) {
            return [];
        }

        $slot = [
            'starts_on' => $activity->starts_on?->toDateString() ?? '',
            'starts_at' => $activity->starts_at,
            'ends_on' => $activity->ends_on?->toDateString() ?? '',
            'ends_at' => $activity->ends_at,
        ];

        $others = self::overlapping($activity->event_id, $slot, $activity->id)
            ->whereHas('speakers', fn (Builder $query) => $query->whereIn('speakers.id', $speakers->modelKeys()))
            ->with('speakers')
            ->orderBy('starts_on')
            ->orderBy('starts_at')
            ->get();

        $warnings = [];

        foreach ($others as $other) {
            foreach ($speakers as $speaker) {
                if ($other->speakers->contains('id', $speaker->id)) {
                    /** @var Speaker $speaker */
                    $warnings[] = ['speaker' => $speaker->full_name, 'activity' => $other->title, 'activity_id' => $other->id];
                }
            }
        }

        return $warnings;
    }

    /**
     * @param  array{starts_on: string, starts_at: string, ends_on: string, ends_at: string}  $slot
     * @return Builder<EventActivity>
     */
    private static function overlapping(string $eventId, array $slot, ?string $exceptActivityId): Builder
    {
        $start = $slot['starts_on'].' '.$slot['starts_at'];
        $end = $slot['ends_on'].' '.$slot['ends_at'];

        return EventActivity::query()
            ->where('event_id', $eventId)
            ->whereNotNull('starts_on')
            ->when($exceptActivityId !== null, fn (Builder $query) => $query->whereKeyNot($exceptActivityId))
            ->whereRaw('(starts_on + starts_at) < ?::timestamp', [$end])
            ->whereRaw('(ends_on + ends_at) > ?::timestamp', [$start]);
    }
}
