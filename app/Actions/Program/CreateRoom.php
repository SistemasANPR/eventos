<?php

declare(strict_types=1);

namespace App\Actions\Program;

use App\Http\Requests\Program\StoreRoomRequest;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Room;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Agrega una sala a un evento (plan 031, DA-2, CA-031-5): queda al final de la lista y solo existe en
 * ese evento. Se ejecuta en la organización DEL EVENTO y deja auditoría. El nombre se compara sin
 * distinguir mayúsculas ni espacios sobrantes.
 *
 * @throws AuthorizationException si quien crea no tiene `activities.manage` en la organización del evento
 * @throws ValidationException si el nombre falta, es muy largo o ya existe en el evento
 */
final class CreateRoom
{
    use AuthorizesInOrganization;

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, Event $event, array $data): Room
    {
        $organization = Organization::query()->findOrFail($event->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $event, $data): Room {
            $this->authorizeActor($actor, 'create', Room::class, $event);

            return DB::transaction(function () use ($actor, $event, $data): Room {
                // Se bloquea el evento para que dos altas simultáneas no tomen la misma posición ni el mismo nombre.
                Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

                $name = $data['name'] ?? null;
                $validated = Validator::make(
                    ['name' => is_string($name) ? trim($name) : $name],
                    StoreRoomRequest::rulesFor($event),
                )->validate();

                $last = Room::query()->where('event_id', $event->id)->max('position');

                $room = new Room;
                $room->event_id = $event->id;
                $room->forceFill(['name' => $validated['name'], 'position' => (int) $last + 1])->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($room)
                    ->event('room_created')
                    ->withProperties([
                        'organization_id' => $room->organization_id,
                        'event_id' => $event->id,
                        'name' => $room->name,
                    ])
                    ->log('room_created');

                return $room;
            });
        });
    }
}
