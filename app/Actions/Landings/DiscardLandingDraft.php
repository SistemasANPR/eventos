<?php

declare(strict_types=1);

namespace App\Actions\Landings;

use App\Exceptions\Domain\LandingVersionConflict;
use App\Models\Event;
use App\Models\EventLanding;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Descarta los cambios sin publicar: el borrador vuelve a ser igual a la versión publicada (plan 022,
 * DA-12, CA-022-18) y se registra `landing_draft_discarded` (identificadores, sin contenido). Sin
 * cambios que descartar no hace nada ni audita. Una landing que nunca se publicó no tiene a qué volver:
 * se rechaza. Mismo control de versión que `SaveLandingDraft`.
 *
 * @throws AuthorizationException si quien descarta no tiene `events.manage` en la organización del evento
 * @throws ValidationException si la landing no tiene versión publicada
 * @throws LandingVersionConflict si la landing cambió desde la versión que trae el editor
 */
final class DiscardLandingDraft
{
    use AuthorizesInOrganization;

    public function handle(User $actor, EventLanding $landing, int $expectedVersion): EventLanding
    {
        $organization = Organization::query()->findOrFail($landing->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $landing, $expectedVersion): EventLanding {
            $this->authorizeActor($actor, 'updateLanding', Event::query()->findOrFail($landing->event_id));

            return DB::transaction(function () use ($actor, $landing, $expectedVersion): EventLanding {
                $current = EventLanding::query()->whereKey($landing->id)->lockForUpdate()->firstOrFail();

                if ($current->version !== $expectedVersion) {
                    throw new LandingVersionConflict;
                }

                if ($current->published_blocks === null) {
                    throw ValidationException::withMessages(['blocks' => __('landings.errors.nothing_to_discard')]);
                }

                if (! $current->hasUnpublishedChanges()) {
                    return $current;
                }

                $current->forceFill(['draft_blocks' => $current->published_blocks, 'version' => $current->version + 1])->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($current)
                    ->event('landing_draft_discarded')
                    ->withProperties([
                        'organization_id' => $current->organization_id,
                        'event_id' => $current->event_id,
                        'landing_id' => $current->id,
                    ])
                    ->log('landing_draft_discarded');

                return $current;
            });
        });
    }
}
