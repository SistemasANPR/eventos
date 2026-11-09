<?php

declare(strict_types=1);

use App\Actions\Organizations\SyncOrganizationRoles;
use App\Enums\OrganizationRole;
use App\Http\Middleware\EnsureActiveMembership;
use App\Http\Middleware\SetOrganizationFromRoute;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

/**
 * Matriz rol × permiso del plan 001 §4, copiada a mano a propósito: si alguien
 * cambia la matriz del código sin cambiar el plan y esta prueba, falla.
 *
 * @return array<string, list<string>>
 */
function expectedMatrix(): array
{
    $organizer = [
        'events.manage', 'calls.manage', 'tracks.manage', 'forms.manage',
        'submissions.view', 'submissions.export', 'files.download', 'mail-templates.view',
    ];

    return [
        'org_admin' => [
            ...$organizer,
            'events.publish', 'privacy_notices.manage', 'team.manage',
            'mail-templates.manage', 'personal_data.manage',
        ],
        'organizer' => $organizer,
        'reviewer' => [],
    ];
}

/** @return array<string, array{0: string, 1: string}> */
function rolePermissionPairs(): array
{
    $permissions = array_unique(array_merge(...array_values(expectedMatrix())));
    $pairs = [];

    foreach (array_keys(expectedMatrix()) as $role) {
        foreach ($permissions as $permission) {
            $pairs["{$role} / {$permission}"] = [$role, $permission];
        }
    }

    return $pairs;
}

afterEach(function (): void {
    CurrentOrganization::forget();
});

it('CA-001-19: seeds exactly the roles and permissions of the matrix', function (): void {
    $organization = Organization::factory()->create();

    app(SyncOrganizationRoles::class)->handle($organization);

    $seeded = Role::query()->where('organization_id', $organization->id)->with('permissions')->get()
        ->mapWithKeys(fn (Role $role): array => [$role->name => $role->permissions->pluck('name')->sort()->values()->all()])
        ->sortKeys()
        ->all();

    $expected = collect(expectedMatrix())
        ->map(fn (array $permissions): array => collect($permissions)->sort()->values()->all())
        ->sortKeys()
        ->all();

    expect($seeded)->toBe($expected);
});

it('CA-001-19: running the seeder twice leaves no duplicates', function (): void {
    $organization = Organization::factory()->create();

    app(SyncOrganizationRoles::class)->handle($organization);
    app(SyncOrganizationRoles::class)->handle($organization);

    expect(Role::query()->where('organization_id', $organization->id)->count())->toBe(3)
        ->and(Permission::query()->count())->toBe(count(array_unique(array_merge(...array_values(expectedMatrix())))));
});

it('CA-001-19: each organization gets its own roles', function (): void {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    app(SyncOrganizationRoles::class)->handle($organizationA);
    app(SyncOrganizationRoles::class)->handle($organizationB);

    expect(Role::query()->where('organization_id', $organizationA->id)->count())->toBe(3)
        ->and(Role::query()->where('organization_id', $organizationB->id)->count())->toBe(3)
        ->and(OrganizationRole::cases())->toHaveCount(3);
});

it('CA-001-20: a role grants a permission only when the matrix says so', function (string $role, string $permission): void {
    $organization = Memberships::organization();
    $user = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::from($role));
    $allowed = in_array($permission, expectedMatrix()[$role], true);

    Route::middleware(['web', SetOrganizationFromRoute::class, EnsureActiveMembership::class])
        ->get('/{organization:slug}/probe/{permission}', function (Organization $organization, string $permission) {
            Gate::authorize($permission);

            return response()->json(['ok' => true]);
        });

    $response = $this->actingAs($user)->get("/{$organization->slug}/probe/{$permission}");

    CurrentOrganization::run($organization, fn () => expect($user->fresh()->can($permission))->toBe($allowed));
    $allowed ? $response->assertOk() : $response->assertForbidden();
})->with(fn (): array => rolePermissionPairs());

it('CA-001-20: a role in one organization grants nothing in another', function (): void {
    $organizationA = Memberships::organization();
    $organizationB = Memberships::organization();
    $user = Memberships::attach(User::factory()->create(), $organizationA, OrganizationRole::OrgAdmin);
    Memberships::attach($user, $organizationB);

    CurrentOrganization::run($organizationA, fn () => expect($user->fresh()->can('team.manage'))->toBeTrue());
    CurrentOrganization::run($organizationB, fn () => expect($user->fresh()->can('team.manage'))->toBeFalse());
});

it('CA-001-21: an action without a policy is denied by default', function (): void {
    $organization = Memberships::organization();
    $user = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $contact = Contact::factory()->create(['organization_id' => $organization->id]);

    CurrentOrganization::run($organization, function () use ($user, $contact): void {
        expect(Gate::forUser($user)->allows('view', $contact))->toBeFalse()
            ->and(Gate::forUser($user)->allows('not-a-real-ability'))->toBeFalse();
    });
});
