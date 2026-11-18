<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Tenancy\Exceptions\MissingOrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
});

it('CA-001-11: denies withoutOrganizationScope to a guest', function (): void {
    expect(fn () => Contact::withoutOrganizationScope()->get())->toThrow(AuthorizationException::class);
});

it('CA-001-11: denies withoutOrganizationScope to a user without the platform mark', function (): void {
    Contact::factory()->create();
    $this->actingAs(User::factory()->create());

    expect(fn () => Contact::withoutOrganizationScope()->get())->toThrow(AuthorizationException::class)
        ->and(Activity::query()->where('event', 'organization_scope_bypassed')->count())->toBe(0);
});

it('CA-001-11: allows a platform user to read across organizations and audits who and which model', function (): void {
    Contact::factory()->count(2)->create();
    Contact::factory()->create();
    $platform = User::factory()->platformAdmin()->create();
    $this->actingAs($platform);

    $total = Contact::withoutOrganizationScope()->count();

    $log = Activity::query()->where('event', 'organization_scope_bypassed')->sole();
    expect($total)->toBe(3)
        ->and($log->causer_id)->toBe($platform->id)
        ->and($log->properties->get('model'))->toBe(Contact::class);
});

it('CA-001-17: a platform user without membership cannot open the panel of an organization', function (): void {
    $organization = Memberships::organization();
    $platform = User::factory()->platformAdmin()->create();

    $response = $this->actingAs($platform)->get("/admin/{$organization->slug}");

    expect($response->status())->toBeIn([403, 404]);
});

it('CA-001-17: a platform user without membership cannot query a tenant model without the explicit bypass', function (): void {
    Contact::factory()->create();
    $this->actingAs(User::factory()->platformAdmin()->create());

    expect(fn () => Contact::query()->get())->toThrow(MissingOrganizationContext::class);
});

it('CA-001-17: a platform user with another organization in context does not see organization A', function (): void {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();
    Contact::factory()->create(['organization_id' => $organizationA->id]);
    $this->actingAs(User::factory()->platformAdmin()->create());

    CurrentOrganization::set($organizationB);

    expect(Contact::query()->count())->toBe(0);
});
