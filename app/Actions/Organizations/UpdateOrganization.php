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
 * Cambia el nombre y, si se envía, el identificador (`slug`) de una organización
 * desde su propio panel (plan 028). Cambiar el `slug` deja de resolver las URL
 * anteriores (sin redirecciones, por decisión expresa). Solo un administrador
 * activo de esa organización; deja auditoría con los valores anteriores y nuevos.
 *
 * @throws AuthorizationException si quien edita no administra esa organización
 * @throws ValidationException si el nombre o el identificador no cumplen las reglas
 */
final class UpdateOrganization
{
    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, Organization $organization, array $data): Organization
    {
        Gate::forUser($actor)->authorize('update', $organization);

        $validated = Validator::make($data, UpdateOrganizationRequest::rulesFor($organization))->validate();

        return DB::transaction(function () use ($actor, $organization, $validated): Organization {
            $current = Organization::query()->whereKey($organization->id)->lockForUpdate()->sole();
            $old = ['name' => $current->name, 'slug' => $current->slug];
            $new = ['name' => $validated['name'], 'slug' => $validated['slug'] ?? $current->slug];

            if ($old === $new) {
                return $current;
            }

            $current->forceFill($new)->save();

            activity()
                ->causedBy($actor)
                ->performedOn($current)
                ->event('organization_updated')
                ->withProperties(['organization_id' => $current->id, 'old' => $old, 'new' => $new])
                ->log('organization_updated');

            return $current;
        });
    }
}
