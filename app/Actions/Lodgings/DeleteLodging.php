<?php

declare(strict_types=1);

namespace App\Actions\Lodgings;

use App\Models\Event;
use App\Models\Lodging;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Lodgings\StoresLodgingImages;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Elimina un hospedaje (plan 030, DA-2 y DA-4, CA-030-3, CA-030-7, CA-030-15). Solo `org_admin` (`lodgings.delete`).
 * El borrado es físico: no hay datos personales que conservar. Sus filas de imágenes se borran en cascada y, tras
 * confirmar la transacción, también sus archivos del disco. Deja auditoría con identificadores.
 *
 * @throws AuthorizationException si quien elimina no tiene `lodgings.delete` en la organización del hospedaje
 */
final class DeleteLodging
{
    use AuthorizesInOrganization, StoresLodgingImages;

    public function handle(User $actor, Lodging $lodging): void
    {
        $organization = Organization::query()->findOrFail($lodging->organization_id);

        CurrentOrganization::run($organization, function () use ($actor, $lodging): void {
            $this->authorizeActor($actor, 'delete', $lodging);

            $paths = [];

            DB::transaction(function () use ($actor, $lodging, &$paths): void {
                $paths = $lodging->images()->pluck('path')->all();
                $lodging->delete();

                activity()
                    ->causedBy($actor)
                    ->performedOn($lodging)
                    ->event('lodging_deleted')
                    ->withProperties([
                        'organization_id' => $lodging->organization_id,
                        'event_id' => $lodging->event_id,
                        'lodging_id' => $lodging->id,
                    ])
                    ->log('lodging_deleted');
            });

            $this->discardImages(Event::query()->findOrFail($lodging->event_id), $paths);
        });
    }
}
