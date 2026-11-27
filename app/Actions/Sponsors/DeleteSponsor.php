<?php

declare(strict_types=1);

namespace App\Actions\Sponsors;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Sponsor;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Extras\ExtraFields;
use App\Support\Sponsors\StoresSponsorLogo;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Elimina un patrocinador (plan 030, DA-2 y DA-4, CA-030-6, CA-030-7). Solo `org_admin` (`sponsors.delete`).
 * El borrado es físico y, tras confirmar la transacción, se borra también su logotipo del disco. Deja
 * auditoría con identificadores.
 *
 * @throws AuthorizationException si quien elimina no tiene `sponsors.delete` en la organización del patrocinador
 */
final class DeleteSponsor
{
    use AuthorizesInOrganization, StoresSponsorLogo;

    public function handle(User $actor, Sponsor $sponsor): void
    {
        $organization = Organization::query()->findOrFail($sponsor->organization_id);

        CurrentOrganization::run($organization, function () use ($actor, $sponsor): void {
            $this->authorizeActor($actor, 'delete', $sponsor);

            DB::transaction(function () use ($actor, $sponsor): void {
                $sponsor->delete();

                activity()
                    ->causedBy($actor)
                    ->performedOn($sponsor)
                    ->event('sponsor_deleted')
                    ->withProperties([
                        'organization_id' => $sponsor->organization_id,
                        'event_id' => $sponsor->event_id,
                        'sponsor_id' => $sponsor->id,
                    ])
                    ->log('sponsor_deleted');
            });

            $event = Event::query()->findOrFail($sponsor->event_id);

            if ($sponsor->logo_path !== null) {
                $this->discardLogo($event, $sponsor->logo_path);
            }

            ExtraFields::discard($event, ExtraFields::paths($sponsor->extra_fields));
        });
    }
}
