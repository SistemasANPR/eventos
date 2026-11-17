<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Reactiva una organización suspendida (plan 016, CA-016-16): sus miembros
 * recuperan el acceso y queda auditado. Idempotente. Solo plataforma.
 *
 * @throws AuthorizationException si quien reactiva no es de plataforma
 */
final class ReactivateOrganization
{
    public function handle(User $actor, Organization $organization): Organization
    {
        Gate::forUser($actor)->authorize('reactivate', $organization);

        return DB::transaction(function () use ($actor, $organization): Organization {
            $current = Organization::query()->whereKey($organization->id)->lockForUpdate()->sole();

            if ($current->status === OrganizationStatus::Active) {
                return $current;
            }

            $current->forceFill(['status' => OrganizationStatus::Active])->save();

            activity()
                ->causedBy($actor)
                ->performedOn($current)
                ->event('organization_reactivated')
                ->withProperties([
                    'organization_id' => $current->id,
                    'old' => ['status' => OrganizationStatus::Suspended->value],
                    'new' => ['status' => OrganizationStatus::Active->value],
                ])
                ->log('organization_reactivated');

            return $current;
        });
    }
}
