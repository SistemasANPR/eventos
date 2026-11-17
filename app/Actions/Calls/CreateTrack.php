<?php

declare(strict_types=1);

namespace App\Actions\Calls;

use App\Http\Requests\Calls\StoreTrackRequest;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Track;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Calls\CallAttributes;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Agrega un eje temático a un evento (plan 004, H-004-2, CA-004-12): queda al
 * final de la lista y solo existe en ese evento. Se ejecuta en la organización
 * DEL EVENTO. Deja auditoría.
 *
 * @throws AuthorizationException si quien crea no tiene `tracks.manage` en la organización del evento
 * @throws ValidationException si falta el nombre en el idioma base del evento
 */
final class CreateTrack
{
    use AuthorizesInOrganization;

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, Event $event, array $data): Track
    {
        $organization = Organization::query()->findOrFail($event->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $event, $data): Track {
            $this->authorizeActor($actor, 'create', Track::class, $event);

            $validated = Validator::make($data, StoreTrackRequest::rulesFor($event))->validate();

            return DB::transaction(function () use ($actor, $event, $validated): Track {
                // Se bloquea el evento para que dos altas simultáneas no tomen la misma posición.
                Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
                $last = Track::query()->where('event_id', $event->id)->max('position');

                $track = new Track;
                $track->event_id = $event->id;
                $track->forceFill([
                    'name' => CallAttributes::texts($validated['name']),
                    'description' => CallAttributes::texts($validated['description'] ?? []),
                    'position' => (int) $last + 1,
                ])->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($track)
                    ->event('track_created')
                    ->withProperties([
                        'organization_id' => $track->organization_id,
                        'event_id' => $event->id,
                        'name' => $track->getTranslations('name'),
                        'description' => $track->getTranslations('description'),
                    ])
                    ->log('track_created');

                return $track;
            });
        });
    }
}
