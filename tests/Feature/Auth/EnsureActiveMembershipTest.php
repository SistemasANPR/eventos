<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Http\Middleware\EnsureActiveMembership;
use App\Http\Middleware\SetOrganizationFromRoute;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Route::middleware(['web', SetOrganizationFromRoute::class, EnsureActiveMembership::class])
        ->get('/{organization:slug}/panel', fn () => response()->json(['ok' => true]));
});

afterEach(function (): void {
    CurrentOrganization::forget();
});

it('CA-001-22: a user without any membership gets no access', function (): void {
    $organization = Memberships::organization();
    $user = User::factory()->create();

    $this->actingAs($user)->get("/{$organization->slug}/panel")->assertForbidden();
});

it('CA-001-22: a user with membership only in another organization gets no access', function (): void {
    $organizationA = Memberships::organization();
    $organizationB = Memberships::organization();
    $user = Memberships::attach(User::factory()->create(), $organizationA, OrganizationRole::OrgAdmin);

    $this->actingAs($user)->get("/{$organizationA->slug}/panel")->assertOk();
    $this->actingAs($user)->get("/{$organizationB->slug}/panel")->assertForbidden();
});

it('CA-001-24: a deactivated membership is refused on an open session', function (): void {
    $organization = Memberships::organization();
    $user = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer);

    $this->actingAs($user)->get("/{$organization->slug}/panel")->assertOk();

    $user->organizations()->updateExistingPivot($organization->id, ['status' => 'deactivated', 'deactivated_at' => now()]);

    $this->actingAs($user->fresh())->get("/{$organization->slug}/panel")->assertForbidden();
});

it('CA-001-24: a membership with deactivated_at set is refused even if the status says active', function (): void {
    $organization = Memberships::organization();
    $user = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer);
    $user->organizations()->updateExistingPivot($organization->id, ['deactivated_at' => now()]);

    $this->actingAs($user->fresh())->get("/{$organization->slug}/panel")->assertForbidden();
});

it('CA-001-24: a deactivated user who signs in is refused', function (): void {
    $organization = Memberships::organization();
    $user = Memberships::attach(User::factory()->create(), $organization, null, 'deactivated');

    $this->actingAs($user)->get("/{$organization->slug}/panel")->assertForbidden();
});

it('CA-001-22: an anonymous visitor is not let in', function (): void {
    $organization = Memberships::organization();

    $this->get("/{$organization->slug}/panel")->assertForbidden();
});
