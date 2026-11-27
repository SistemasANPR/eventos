<?php

declare(strict_types=1);

namespace App\Actions\Landings;

use App\Exceptions\Domain\LandingVersionConflict;
use App\Models\Event;
use App\Models\EventLanding;
use App\Models\LandingImage;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Landings\LandingBlocks;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Guarda el borrador de la landing (plan 022, DA-12, CA-022-6, 9 a 15, 17). No toca la versión publicada.
 *
 * Todo o nada: los bloques se validan y normalizan con `LandingBlocks` (lista blanca de tipos, campos
 * por tipo, límites, texto con formato saneado, enlaces `https`, alternativas de las imágenes) y las
 * imágenes que referencian deben ser de ESTA landing y no estar borradas. Los identificadores de los
 * bloques los fija el servidor (CA-022-14). Control optimista: `$expectedVersion` es la versión desde la
 * que editó el cliente; si otra edición se guardó antes se lanza `LandingVersionConflict` y no se pisa
 * nada (CA-022-15). Un guardado idéntico a lo guardado no cambia nada ni sube la versión.
 *
 * @param  mixed  $blocks  lista de bloques tal como llega del editor (no se confía en ella)
 *
 * @throws AuthorizationException si quien guarda no tiene `events.manage` en la organización del evento
 * @throws ValidationException si algún bloque no es válido (no se guarda ninguno)
 * @throws LandingVersionConflict si la landing cambió desde la versión que trae el editor
 */
final class SaveLandingDraft
{
    use AuthorizesInOrganization;

    public function handle(User $actor, EventLanding $landing, mixed $blocks, int $expectedVersion): EventLanding
    {
        $organization = Organization::query()->findOrFail($landing->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $landing, $blocks, $expectedVersion): EventLanding {
            $event = Event::query()->findOrFail($landing->event_id);
            $this->authorizeActor($actor, 'updateLanding', $event);

            return DB::transaction(function () use ($event, $landing, $blocks, $expectedVersion): EventLanding {
                $current = EventLanding::query()->whereKey($landing->id)->lockForUpdate()->firstOrFail();

                if ($current->version !== $expectedVersion) {
                    throw new LandingVersionConflict;
                }

                $clean = LandingBlocks::validate($event, $blocks, array_column($current->draft_blocks, 'id'), $current->isForCall());
                $this->assertImagesBelong($current, $clean);

                if (LandingBlocks::same($current->draft_blocks, $clean)) {
                    return $current;
                }

                $current->forceFill(['draft_blocks' => $clean, 'version' => $current->version + 1])->save();

                return $current;
            });
        });
    }

    /**
     * Cada imagen referenciada existe, no está borrada y es de esta landing (otra landing, evento u
     * organización no se ve por el filtro de organización y la condición de landing).
     *
     * @param  list<array<string, mixed>>  $clean
     *
     * @throws ValidationException
     */
    private function assertImagesBelong(EventLanding $landing, array $clean): void
    {
        $ids = LandingBlocks::imageIds($clean);

        if ($ids === []) {
            return;
        }

        $found = LandingImage::query()->where('event_landing_id', $landing->id)->whereIn('id', $ids)->pluck('id')->all();

        if (count($found) !== count($ids)) {
            throw ValidationException::withMessages(['blocks' => __('landings.errors.image_not_found')]);
        }
    }
}
