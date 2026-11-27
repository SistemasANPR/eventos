<?php

declare(strict_types=1);

namespace App\Actions\Program;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Room;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Sube o baja una sala un lugar en la lista del evento (plan 031, CA-031-8): intercambia su posición
 * con la vecina y deja las posiciones consecutivas (1, 2, 3...), aunque una eliminación haya dejado
 * huecos. La primera no sube y la última no baja: no cambia nada y no audita. El orden de la lista es
 * el de las columnas de la agenda. Se ejecuta en la organización DE LA SALA y deja auditoría.
 *
 * @throws AuthorizationException si quien la mueve no tiene `activities.manage` en la organización de la sala
 * @throws InvalidArgumentException si la dirección no es `up` ni `down`
 */
final class MoveRoom
{
    use AuthorizesInOrganization;

    public const UP = 'up';

    public const DOWN = 'down';

    public function handle(User $actor, Room $room, string $direction): Room
    {
        if (! in_array($direction, [self::UP, self::DOWN], true)) {
            throw new InvalidArgumentException("Dirección de sala desconocida: {$direction}.");
        }

        $organization = Organization::query()->findOrFail($room->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $room, $direction): Room {
            $this->authorizeActor($actor, 'update', $room);

            return DB::transaction(function () use ($actor, $room, $direction): Room {
                // Se bloquea el evento para que dos movimientos simultáneos no lean el mismo orden.
                Event::query()->whereKey($room->event_id)->lockForUpdate()->firstOrFail();

                $ordered = Room::query()->where('event_id', $room->event_id)->orderBy('position')->orderBy('id')->get()->values();
                $index = $ordered->search(fn (Room $candidate): bool => $candidate->id === $room->id);

                if ($index === false) {
                    return $room;
                }

                $target = $direction === self::UP ? $index - 1 : $index + 1;

                if ($target < 0 || $target >= $ordered->count()) {
                    return $room;
                }

                $previous = $room->position;
                $sequence = $ordered->all();
                [$sequence[$index], $sequence[$target]] = [$sequence[$target], $sequence[$index]];

                foreach ($sequence as $position => $candidate) {
                    if ($candidate->position !== $position + 1) {
                        $candidate->forceFill(['position' => $position + 1])->save();
                    }
                }

                $room->refresh();

                activity()
                    ->causedBy($actor)
                    ->performedOn($room)
                    ->event('room_moved')
                    ->withProperties([
                        'organization_id' => $room->organization_id,
                        'event_id' => $room->event_id,
                        'old' => ['position' => $previous],
                        'new' => ['position' => $room->position],
                    ])
                    ->log('room_moved');

                return $room;
            });
        });
    }
}
