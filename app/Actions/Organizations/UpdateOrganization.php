<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Http\Requests\Organizations\UpdateOrganizationRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Cambia el nombre de una organización desde su propio panel (plan 028). El
 * `slug` no se toca: va en las direcciones públicas. Solo un administrador
 * activo de esa organización; deja auditoría con el nombre anterior y el nuevo.
 *
 * @throws AuthorizationException si quien edita no administra esa organización
 * @throws ValidationException si el nombre no cumple las reglas
 */
final class UpdateOrganization
{
    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, Organization $organization, array $data): Organization
    {
        Gate::forUser($actor)->authorize('update', $organization);

        $validated = Validator::make($data, UpdateOrganizationRequest::rulesFor())->validate();

        return DB::transaction(function () use ($actor, $organization, $validated): Organization {
            $current = Organization::query()->whereKey($organization->id)->lockForUpdate()->sole();
            $previous = $current->name;

            if ($previous === $validated['name']) {
                return $current;
            }

            $current->forceFill(['name' => $validated['name']])->save();

            activity()
                ->causedBy($actor)
                ->performedOn($current)
                ->event('organization_updated')
                ->withProperties([
                    'organization_id' => $current->id,
                    'old' => ['name' => $previous],
                    'new' => ['name' => $current->name],
                ])
                ->log('organization_updated');

            return $current;
        });
    }
}
