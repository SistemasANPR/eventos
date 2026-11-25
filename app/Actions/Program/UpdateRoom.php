<?php

declare(strict_types=1);

namespace App\Actions\Program;

use App\Http\Requests\Program\UpdateRoomRequest;
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
 * Cambia el nombre de una sala (plan 031, CA-031-5). Se ejecuta en la organización DE LA SALA y deja
 * auditoría con el nombre anterior y el nuevo.
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
            $name = $data['name'] ?? null;

            $validated = Validator::make(
                ['name' => is_string($name) ? trim($name) : $name],
                UpdateRoomRequest::rulesFor($event, $room->id),
            )->validate();

            return DB::transaction(function () use ($actor, $room, $validated): Room {
                $previous = $room->name;

                $room->forceFill(['name' => $validated['name']])->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($room)
                    ->event('room_updated')
                    ->withProperties([
                        'organization_id' => $room->organization_id,
                        'event_id' => $room->event_id,
                        'old' => ['name' => $previous],
                        'new' => ['name' => $room->name],
                    ])
                    ->log('room_updated');

                return $room;
            });
        });
    }
}
