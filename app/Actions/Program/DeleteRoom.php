<?php

declare(strict_types=1);

namespace App\Actions\Program;

use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Organization;
use App\Models\Room;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Elimina una sala (plan 031, DA-13, CA-031-7). Solo `org_admin` (`activities.delete`). Una sala con
 * actividades asignadas NO se elimina: el error dice cuántas son y no se las deja sin sala en silencio.
 * Una actividad eliminada no cuenta; su vínculo con la sala se suelta para que la clave foránea
 * restrictiva no impida el borrado. Se ejecuta en la organización DE LA SALA y deja auditoría.
 *
 * @throws AuthorizationException si quien elimina no tiene `activities.delete` en la organización de la sala
 * @throws ValidationException si la sala tiene actividades asignadas
 */
final class DeleteRoom
{
    use AuthorizesInOrganization;

    public function handle(User $actor, Room $room): void
    {
        $organization = Organization::query()->findOrFail($room->organization_id);

        CurrentOrganization::run($organization, function () use ($actor, $room): void {
            $this->authorizeActor($actor, 'delete', $room);

            DB::transaction(function () use ($actor, $room): void {
                // El mismo bloqueo del evento que usarán los cambios de horario y de sala: nadie asigna la sala mientras se borra.
                Event::query()->whereKey($room->event_id)->lockForUpdate()->firstOrFail();

                $inUse = EventActivity::query()->where('room_id', $room->id)->count();

                if ($inUse > 0) {
                    throw ValidationException::withMessages(['room' => __('program.rooms.in_use', ['count' => $inUse])]);
                }

                EventActivity::onlyTrashed()->where('room_id', $room->id)->update(['room_id' => null]);

                $snapshot = $room->name;
                $room->delete();

                activity()
                    ->causedBy($actor)
                    ->performedOn($room)
                    ->event('room_deleted')
                    ->withProperties([
                        'organization_id' => $room->organization_id,
                        'event_id' => $room->event_id,
                        'old' => ['name' => $snapshot],
                    ])
                    ->log('room_deleted');
            });
        });
    }
}
