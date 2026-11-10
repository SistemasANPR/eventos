<?php

declare(strict_types=1);

use App\Actions\Organizations\SyncOrganizationRoles;
use App\Models\Organization;
use Illuminate\Database\Migrations\Migration;

/**
 * MIGRACIÓN DE DATOS (separada de la de esquema): la matriz rol × permiso ganó
 * `calls.view` (plan 004, DA-3). Las organizaciones ya creadas necesitan que se
 * vuelvan a sembrar sus roles; la Action es idempotente. Las organizaciones
 * nuevas ya nacen con la matriz vigente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Organization::query()->each(
            fn (Organization $organization) => app(SyncOrganizationRoles::class)->handle($organization),
        );
    }

    public function down(): void
    {
        // Sin reversa: quitar un permiso de la matriz es un cambio de la matriz, no de datos.
    }
};
