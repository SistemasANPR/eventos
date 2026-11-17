<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Enums\OrganizationStatus;
use App\Http\Requests\Platform\SuspendOrganizationRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Suspende una organización (plan 016, CA-016-13 a 15). El sitio público y el
 * panel de la organización dejan de responder; los datos no se tocan. El motivo
 * es obligatorio y queda auditado con quién, cuándo y los estados anterior y
 * nuevo. Es idempotente: suspender una ya suspendida no cambia nada ni duplica
 * el registro. Solo plataforma.
 *
 * @throws AuthorizationException si quien suspende no es de plataforma
 * @throws ValidationException si falta el motivo o no cumple el largo
 */
final class SuspendOrganization
{
    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, Organization $organization, array $data): Organization
    {
        Gate::forUser($actor)->authorize('suspend', $organization);

        $validated = Validator::make($data, SuspendOrganizationRequest::rulesFor())->validate();

        return DB::transaction(function () use ($actor, $organization, $validated): Organization {
            // Con la fila bloqueada, dos suspensiones simultáneas no registran dos veces.
            $current = Organization::query()->whereKey($organization->id)->lockForUpdate()->sole();

            if ($current->status === OrganizationStatus::Suspended) {
                return $current;
            }

            $current->forceFill(['status' => OrganizationStatus::Suspended])->save();

            activity()
                ->causedBy($actor)
                ->performedOn($current)
                ->event('organization_suspended')
                ->withProperties([
                    'organization_id' => $current->id,
                    'reason' => $validated['reason'],
                    'old' => ['status' => OrganizationStatus::Active->value],
                    'new' => ['status' => OrganizationStatus::Suspended->value],
                ])
                ->log('organization_suspended');

            return $current;
        });
    }
}
