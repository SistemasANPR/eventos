<?php

declare(strict_types=1);

namespace App\Actions\Calls;

use App\Http\Requests\Calls\ReorderTracksRequest;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Track;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Reordena los ejes de un evento (plan 004, H-004-2, CA-004-12): recibe los
 * identificadores de TODOS sus ejes en el orden nuevo y les asigna las
 * posiciones 1..n. Se ejecuta en la organización DEL EVENTO y deja auditoría
 * solo si el orden cambió.
 *
 * @throws AuthorizationException si quien ordena no tiene `tracks.manage` en la organización del evento
 * @throws ValidationException si la lista no contiene exactamente los ejes del evento
 */
final class ReorderTracks
{
    use AuthorizesInOrganization;

    /**
     * @param  list<string>  $order  identificadores de los ejes, en el orden nuevo
     * @return list<string> el orden resultante
     */
    public function handle(User $actor, Event $event, array $order): array
    {
        $organization = Organization::query()->findOrFail($event->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $event, $order): array {
            $this->authorizeActor($actor, 'reorder', Track::class, $event);

            return DB::transaction(function () use ($actor, $event, $order): array {
                // Se bloquea el evento para serializar reordenamientos y altas simultáneas.
                Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
                $validated = Validator::make(['order' => $order], ReorderTracksRequest::rulesFor($event))->validate();

                /** @var list<string> $newOrder */
                $newOrder = array_values($validated['order']);
                $current = Track::query()->where('event_id', $event->id)->orderBy('position')->orderBy('id')->get();
                $previous = $current->pluck('id')->all();

                if ($previous === $newOrder) {
                    return $newOrder;
                }

                $byId = $current->keyBy('id');

                foreach ($newOrder as $index => $id) {
                    $byId[$id]->forceFill(['position' => $index + 1])->save();
                }

                activity()
                    ->causedBy($actor)
                    ->performedOn($event)
                    ->event('track_reordered')
                    ->withProperties(['organization_id' => $event->organization_id, 'old' => ['order' => $previous], 'new' => ['order' => $newOrder]])
                    ->log('track_reordered');

                return $newOrder;
            });
        });
    }
}
