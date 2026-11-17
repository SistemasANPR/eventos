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
 * Elimina una actividad con borrado lógico (plan 021, DA-13, CA-021-16). Solo `org_admin`
 * (`activities.delete`). No borra a los ponentes asignados ni sus imágenes (la fila puede
 * recuperarse). Se ejecuta en la organización DE LA ACTIVIDAD y deja auditoría.
 *
 * @throws AuthorizationException si quien elimina no tiene `activities.delete` en la organización de la actividad
 */
final class DeleteActivity
{
    use AuthorizesInOrganization;

    public function handle(User $actor, EventActivity $activity): void
    {
        $organization = Organization::query()->findOrFail($activity->organization_id);

        CurrentOrganization::run($organization, function () use ($actor, $activity): void {
            $this->authorizeActor($actor, 'delete', $activity);

            DB::transaction(function () use ($actor, $activity): void {
                $activity->delete();

                activity()
                    ->causedBy($actor)
                    ->performedOn($activity)
                    ->event('activity_deleted')
                    ->withProperties([
                        'organization_id' => $activity->organization_id,
                        'event_id' => $activity->event_id,
                    ])
                    ->log('activity_deleted');
            });
        });
    }
}
