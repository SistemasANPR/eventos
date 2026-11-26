<?php

declare(strict_types=1);

namespace App\Actions\Landings;

use App\Models\CallForSubmissions;
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
 * Crea la landing PROPIA de una convocatoria con la plantilla de convocatoria (plan 033). Nace solo en borrador:
 * la página pública de la convocatoria no cambia hasta que el organizador pulse «Actualizar». Es idempotente (si
 * la convocatoria ya tiene landing, devuelve esa sin tocarla), se ejecuta en la organización del evento y en una
 * transacción, y deja auditoría con identificadores y sin contenido.
 *
 * @throws AuthorizationException si quien crea no tiene `events.manage` en la organización del evento
 */
final class CreateCallLanding
{
    use AuthorizesInOrganization;

    public function handle(User $actor, CallForSubmissions $call): EventLanding
    {
        $organization = Organization::query()->findOrFail($call->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $call): EventLanding {
            $event = Event::query()->findOrFail($call->event_id);
            $this->authorizeActor($actor, 'updateLanding', $event);

            return DB::transaction(function () use ($actor, $call, $event): EventLanding {
                // Se bloquea la convocatoria: dos aperturas simultáneas del editor no crean dos landings.
                CallForSubmissions::query()->whereKey($call->id)->lockForUpdate()->firstOrFail();

                $existing = EventLanding::query()->where('call_id', $call->id)->first();

                if ($existing !== null) {
                    return $existing;
                }

                $landing = new EventLanding;
                $landing->event_id = $event->id;
                $landing->call_id = $call->id;
                $landing->forceFill([
                    'draft_blocks' => LandingTemplate::callBlocks($event),
                    'published_blocks' => null,
                    'version' => 1,
                    'published_at' => null,
                    'published_by' => null,
                ])->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($landing)
                    ->event('landing_created')
                    ->withProperties([
                        'organization_id' => $landing->organization_id,
                        'event_id' => $event->id,
                        'call_id' => $call->id,
                        'landing_id' => $landing->id,
                        'published' => false,
                    ])
                    ->log('landing_created');

                return $landing;
            });
        });
    }
}
