<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Enums\OrganizationRole;
use App\Exceptions\Domain\OrganizationAlreadyExists;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\SetPasswordNotification;
use App\Rules\OrganizationSlug;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Crea una organización con su primer `org_admin` (plan 001, CA-001-14 y -15).
 *
 * En una sola transacción: organización, roles sembrados, administrador (se
 * reutiliza por correo si ya existe), membresía activa y rol. Fuera de ella:
 * se encola el correo para fijar contraseña. Es idempotente por `slug`: si ya
 * existe no se toca nada. Quien la invoca (comando de plataforma) es quien
 * autoriza; esta Action no se expone a peticiones HTTP.
 *
 * @throws ValidationException si el slug o el correo son inválidos
 * @throws OrganizationAlreadyExists si el slug ya existe (también entre eliminadas)
 */
final class CreateOrganization
{
    public function __construct(private readonly SyncOrganizationRoles $syncRoles) {}

    public function handle(string $slug, string $name, string $adminEmail, string $adminName): Organization
    {
        // Postgres distingue mayúsculas y el restablecimiento busca en minúsculas.
        $adminEmail = mb_strtolower(trim($adminEmail));

        Validator::make(
            ['slug' => $slug, 'name' => $name, 'email' => $adminEmail, 'admin_name' => $adminName],
            [
                'slug' => ['required', new OrganizationSlug],
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email:rfc', 'max:255'],
                'admin_name' => ['required', 'string', 'max:255'],
            ],
        )->validate();

        [$organization, $admin] = DB::transaction(function () use ($slug, $name, $adminEmail, $adminName): array {
            if (Organization::withTrashed()->where('slug', $slug)->exists()) {
                throw new OrganizationAlreadyExists($slug);
            }

            $organization = Organization::create(['name' => $name, 'slug' => $slug]);
            $this->syncRoles->handle($organization);

            // Un administrador nuevo recibe una contraseña aleatoria que nadie conoce: entra con el enlace.
            $admin = User::query()->firstOrCreate(
                ['email' => $adminEmail],
                ['name' => $adminName, 'password' => Str::random(64)],
            );

            $admin->organizations()->attach($organization->id, ['status' => 'active']);
            CurrentOrganization::run($organization, fn () => $admin->assignRole(OrganizationRole::OrgAdmin->value));

            activity()
                ->performedOn($organization)
                ->event('organization_created')
                ->withProperties(['slug' => $slug, 'admin_id' => $admin->id])
                ->log('organization_created');

            return [$organization, $admin];
        });

        $admin->notify(new SetPasswordNotification);

        return $organization;
    }
}
