<?php

declare(strict_types=1);

namespace App\Actions\Program;

use App\Enums\RoomKind;
use App\Http\Requests\Program\UpdateRoomRequest;
use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Organization;
use App\Models\Room;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Program\RoomWrites;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Edita un lugar (plan 031, CA-031-5; plan 035, CA-035-3 a 6 y 9). El nombre es obligatorio; tipo, capacidad,
 * capacidad pública, dirección y mapa solo cambian si vienen en los datos (nulo vacía capacidad, dirección y mapa).
 * El tipo de un lugar con actividades no cambia: el error dice cuántas lo usan. Se ejecuta en la organización DEL
 * LUGAR, con el evento bloqueado, y deja auditoría con el nombre anterior y el nuevo y los campos que cambiaron.
 *
 * @throws AuthorizationException si quien edita no tiene `activities.manage` en la organización de la sala
 * @throws ValidationException si el nombre falta, es muy largo o ya lo tiene otra sala del evento
 */
final class UpdateRoom
{
    use AuthorizesInOrganization;

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, Room $room, array $data): Room
    {
        $organization = Organization::query()->findOrFail($room->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $room, $data): Room {
            $this->authorizeActor($actor, 'update', $room);

            $event = Event::query()->findOrFail($room->event_id);
            $validated = Validator::make(RoomWrites::normalized($data), UpdateRoomRequest::rulesFor($event, $room->id))->validate();

            return DB::transaction(function () use ($actor, $room, $validated): Room {
                // El mismo bloqueo del evento que `DeleteRoom` y los cambios de horario: nadie asigna el lugar mientras cambia de tipo.
                Event::query()->whereKey($room->event_id)->lockForUpdate()->firstOrFail();

                $newKind = array_key_exists('kind', $validated) ? RoomKind::from((string) $validated['kind']) : $room->kind;

                if ($newKind !== $room->kind) {
                    $inUse = EventActivity::query()->where('room_id', $room->id)->count();

                    if ($inUse > 0) {
                        throw ValidationException::withMessages(['kind' => __('program.rooms.kind_in_use', ['count' => $inUse])]);
                    }
                }

                $previous = $room->name;

                $room->forceFill(['name' => $validated['name'], ...RoomWrites::attributes($validated)])->save();
                $changed = array_values(array_diff(array_keys($room->getChanges()), ['updated_at']));

                activity()
                    ->causedBy($actor)
                    ->performedOn($room)
                    ->event('room_updated')
                    ->withProperties([
                        'organization_id' => $room->organization_id,
                        'event_id' => $room->event_id,
                        'old' => ['name' => $previous],
                        'new' => ['name' => $room->name],
                        'changed' => $changed,
                    ])
                    ->log('room_updated');

                return $room;
            });
        });
    }
}
