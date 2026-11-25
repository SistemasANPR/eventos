<?php

declare(strict_types=1);

namespace App\Actions\Lodgings;

use App\Models\Lodging;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Elimina un hospedaje (plan 030, DA-2 y DA-4, CA-030-3, CA-030-7). Solo `org_admin` (`lodgings.delete`).
 * El borrado es físico: no hay datos personales que conservar. Deja auditoría con identificadores.
 *
 * @throws AuthorizationException si quien elimina no tiene `lodgings.delete` en la organización del hospedaje
 */
final class DeleteLodging
{
    use AuthorizesInOrganization;

    public function handle(User $actor, Lodging $lodging): void
    {
        $organization = Organization::query()->findOrFail($lodging->organization_id);

        CurrentOrganization::run($organization, function () use ($actor, $lodging): void {
            $this->authorizeActor($actor, 'delete', $lodging);

            DB::transaction(function () use ($actor, $lodging): void {
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
        });
    }
}
