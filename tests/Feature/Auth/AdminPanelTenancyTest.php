<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Http\Middleware\ApplyOrganizationContext;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

it('CA-001-18: a member of one organization cannot open the panel of another', function (): void {
    $organizationA = Memberships::organization();
    $organizationB = Memberships::organization();
    $user = Memberships::attach(User::factory()->create(), $organizationA, OrganizationRole::OrgAdmin);

    $this->actingAs($user)->get("/admin/{$organizationA->slug}")->assertOk();

    $denied = $this->actingAs($user)->get("/admin/{$organizationB->slug}");
    expect($denied->status())->toBeIn([403, 404]);
});

it('CA-001-22: a user without membership gets no panel access', function (): void {
    $organization = Memberships::organization();
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get("/admin/{$organization->slug}");

    expect($response->status())->toBeIn([403, 404]);
});

it('CA-001-24: a deactivated member is refused in the panel on an open session', function (): void {
    $organization = Memberships::organization();
    $user = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer);
    $this->actingAs($user)->get("/admin/{$organization->slug}")->assertOk();

    $user->organizations()->updateExistingPivot($organization->id, ['status' => 'deactivated', 'deactivated_at' => now()]);

    $response = $this->actingAs($user->fresh())->get("/admin/{$organization->slug}");
    expect($response->status())->toBeIn([403, 404]);
});

it('CA-001-22: the panel lists only the active organizations of the user', function (): void {
    $active = Memberships::organization();
    $deactivated = Memberships::organization();
    $other = Memberships::organization();
    $user = Memberships::attach(User::factory()->create(), $active, OrganizationRole::Organizer);
    Memberships::attach($user, $deactivated, null, 'deactivated');

    $tenants = $user->getTenants(Filament::getPanel('admin'));

    expect($tenants->pluck('id')->all())->toBe([$active->id])
        ->and($user->canAccessTenant($other))->toBeFalse()
        ->and($user->canAccessTenant($deactivated))->toBeFalse()
        ->and($user->canAccessTenant($active))->toBeTrue();
});

it('CA-001-18: the panel tenant sets the organization context and the permissions team, and clears them after', function (): void {
    $organization = Memberships::organization();
    $this->actingAs(Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin));
    Filament::setTenant($organization);
    $seen = [];

    $response = (new ApplyOrganizationContext)->handle(Request::create('/admin'), function () use (&$seen) {
        $seen = [CurrentOrganization::id(), app(PermissionRegistrar::class)->getPermissionsTeamId()];

        return response('ok');
    });

    expect($response->getContent())->toBe('ok')
        ->and($seen)->toBe([$organization->id, $organization->id])
        ->and(CurrentOrganization::has())->toBeFalse()
        ->and(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBeNull();
});

it('CA-001-18: without a panel tenant the middleware refuses to continue', function (): void {
    Filament::setTenant(null);

    expect(fn () => (new ApplyOrganizationContext)->handle(Request::create('/admin'), fn () => response('ok')))
        ->toThrow(HttpException::class);
});

it('CA-001-18: the public route of an organization named admin does not exist', function (): void {
    // El prefijo del panel es un slug reservado: ninguna organización puede ocuparlo.
    expect(Organization::RESERVED_SLUGS)->toContain('admin');
});
