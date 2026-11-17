<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Models\Organization;
use App\Support\Auth\RolePermissions;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Siembra, de forma idempotente, los permisos de la matriz y los roles de una
 * organización con exactamente sus permisos (plan 001 §4, CA-001-19).
 *
 * Los permisos son globales y los roles pertenecen a la organización (team);
 * volver a ejecutarla corrige una matriz que cambió, sin duplicar nada.
 */
final class SyncOrganizationRoles
{
    public function handle(Organization $organization): void
    {
        DB::transaction(function () use ($organization): void {
            CurrentOrganization::run($organization, function (): void {
                foreach (RolePermissions::permissions() as $permission) {
                    Permission::findOrCreate($permission, 'web');
                }

                foreach (RolePermissions::matrix() as $roleName => $permissions) {
                    Role::findOrCreate($roleName, 'web')->syncPermissions($permissions);
                }
            });
        });
    }
}
