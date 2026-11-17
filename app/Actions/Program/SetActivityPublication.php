<?php

declare(strict_types=1);

namespace App\Actions\Program;

use App\Models\EventActivity;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Publica o despublica una actividad (plan 021, DA-6, DA-13): solo cambia `is_published`, así que
 * sirve también para una actividad «sin programar», que no pasa por el formulario completo. Un cambio
 * manual sustituye a la marca de reversa (DA-3: el organizador puede republicar). Se ejecuta en la
 * organización DE LA ACTIVIDAD y deja auditoría.
 *
 * @throws AuthorizationException si quien cambia no tiene `activities.manage` en la organización de la actividad
 */
final class SetActivityPublication
{
    use AuthorizesInOrganization;

    public function handle(User $actor, EventActivity $activity, bool $published): EventActivity
    {
        $organization = Organization::query()->findOrFail($activity->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $activity, $published): EventActivity {
            $this->authorizeActor($actor, 'update', $activity);

            return DB::transaction(function () use ($actor, $activity, $published): EventActivity {
                $activity->forceFill(['is_published' => $published, 'hidden_by_reversal_at' => null])->save();

                $name = $published ? 'activity_published' : 'activity_unpublished';

                activity()
                    ->causedBy($actor)
                    ->performedOn($activity)
                    ->event($name)
                    ->withProperties([
                        'organization_id' => $activity->organization_id,
                        'event_id' => $activity->event_id,
                    ])
                    ->log($name);

                return $activity;
            });
        });
    }
}
