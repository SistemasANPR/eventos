<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Organizations\SyncOrganizationRoles;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;

/** Ayudas de prueba: membresías y roles de un usuario en una organización. */
final class Memberships
{
    /** Crea la organización con sus roles ya sembrados. */
    public static function organization(): Organization
    {
        $organization = Organization::factory()->create();
        app(SyncOrganizationRoles::class)->handle($organization);

        return $organization;
    }

    /** Une al usuario a la organización y, si se indica, le asigna el rol dentro de ella. */
    public static function attach(
        User $user,
        Organization $organization,
        ?OrganizationRole $role = null,
        string $status = 'active',
    ): User {
        $user->organizations()->attach($organization->id, [
            'status' => $status,
            'deactivated_at' => $status === 'deactivated' ? now() : null,
        ]);

        if ($role !== null) {
            CurrentOrganization::run($organization, fn () => $user->assignRole($role->value));
        }

        return $user->refresh();
    }
}
