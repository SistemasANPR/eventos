<?php

declare(strict_types=1);

namespace App\Actions\Calls;

use App\Enums\CallStatus;
use App\Models\CallForSubmissions;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Quita el formulario de una convocatoria en borrador (plan 022, CA-022-8 y -9). Con la convocatoria
 * abierta, pausada o cerrada ya hay (o puede haber) propuestas que lo usan: ahí solo se cambia, no se quita.
 * El formulario en sí no se toca. Si la convocatoria ya no tiene formulario es idempotente y no audita.
 *
 * @throws AuthorizationException si quien desasigna no tiene `calls.manage` en la organización de la convocatoria
 * @throws ValidationException si la convocatoria no es un borrador (clave `status`)
 */
final class UnassignFormFromCall
{
    use AuthorizesInOrganization;

    public function handle(User $actor, CallForSubmissions $call): CallForSubmissions
    {
        $organization = Organization::query()->findOrFail($call->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $call): CallForSubmissions {
            $this->authorizeActor($actor, 'update', $call);

            return DB::transaction(function () use ($actor, $call): CallForSubmissions {
                // Se relee con bloqueo: el estado y el formulario que se evalúan son los vigentes.
                $current = CallForSubmissions::query()->lockForUpdate()->findOrFail($call->id);

                if ($current->status !== CallStatus::Draft) {
                    throw ValidationException::withMessages(['status' => __('calls.form_only_unassigned_in_draft')]);
                }

                $previousFormId = $current->form_definition_id;

                if ($previousFormId === null) {
                    return $current;
                }

                $current->forceFill(['form_definition_id' => null])->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($current)
                    ->event('call_form_unassigned')
                    ->withProperties([
                        'organization_id' => $current->organization_id,
                        'previous_form_definition_id' => $previousFormId,
                    ])
                    ->log('call_form_unassigned');

                return $current;
            });
        });
    }
}
