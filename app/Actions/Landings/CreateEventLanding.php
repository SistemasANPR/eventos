<?php

declare(strict_types=1);

namespace App\Actions\Landings;

use App\Models\Event;
use App\Models\EventLanding;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Landings\LandingTemplate;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Crea la landing de un evento con la plantilla predeterminada (plan 022, DA-6, CA-022-1, CA-022-2).
 *
 * - Con `$publish = true` (al crear el evento) la plantilla queda en el borrador Y en la versión
 *   publicada: al publicar el evento ya se ve la landing.
 * - Con `$publish = false` (evento anterior al plan, al abrir el editor por primera vez) solo queda en el
 *   borrador: la página pública no cambia hasta que el organizador actualice.
 *
 * Es idempotente: si el evento ya tiene landing, devuelve esa sin tocarla. Se ejecuta en la organización
 * DEL EVENTO y en una transacción (anidada si la llama `CreateEvent`, de modo que un fallo aquí deshace
 * también el evento). Deja auditoría con identificadores y sin contenido.
 *
 * @throws AuthorizationException si quien crea no tiene `events.manage` en la organización del evento
 */
final class CreateEventLanding
{
    use AuthorizesInOrganization;

    public function handle(User $actor, Event $event, bool $publish = false): EventLanding
    {
        $organization = Organization::query()->findOrFail($event->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $event, $publish): EventLanding {
            $this->authorizeActor($actor, 'updateLanding', $event);

            return DB::transaction(function () use ($actor, $event, $publish): EventLanding {
                // Se bloquea el evento: dos aperturas simultáneas del editor no crean dos landings.
                Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

                $existing = EventLanding::query()->where('event_id', $event->id)->whereNull('call_id')->first();

                if ($existing !== null) {
                    return $existing;
                }

                $blocks = LandingTemplate::blocks($event);

                $landing = new EventLanding;
                $landing->event_id = $event->id;
                $landing->forceFill([
                    'draft_blocks' => $blocks,
                    'published_blocks' => $publish ? $blocks : null,
                    'version' => 1,
                    'published_at' => $publish ? now() : null,
                    'published_by' => $publish ? $actor->id : null,
                ])->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($landing)
                    ->event('landing_created')
                    ->withProperties([
                        'organization_id' => $landing->organization_id,
                        'event_id' => $event->id,
                        'landing_id' => $landing->id,
                        'published' => $publish,
                    ])
                    ->log('landing_created');

                return $landing;
            });
        });
    }
}
