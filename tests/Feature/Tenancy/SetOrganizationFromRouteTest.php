<?php

declare(strict_types=1);

use App\Enums\OrganizationStatus;
use App\Http\Middleware\SetOrganizationFromRoute;
use App\Models\Contact;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Route::middleware(['web', SetOrganizationFromRoute::class])
        ->prefix('/{organization:slug}')
        ->scopeBindings()
        ->group(function (): void {
            Route::get('/contacts/{contact}', fn (Organization $organization, Contact $contact) => response()->json([
                'organization' => $organization->id,
                'context' => CurrentOrganization::get()->id,
                'contact' => $contact->id,
            ]));
            Route::get('/whoami', fn () => response()->json(['context' => CurrentOrganization::get()->id]));
            Route::get('/boom', fn () => throw new RuntimeException('boom'));
        });
});

afterEach(function (): void {
    CurrentOrganization::forget();
});

it('CA-001-5: a contact of another organization answers 404, not 403', function (): void {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();
    $contactOfB = Contact::factory()->create(['organization_id' => $organizationB->id]);

    $this->get("/{$organizationA->slug}/contacts/{$contactOfB->id}")->assertNotFound();
});

it('CA-001-5: a contact of the same organization is found', function (): void {
    $organization = Organization::factory()->create();
    $contact = Contact::factory()->create(['organization_id' => $organization->id]);

    $this->get("/{$organization->slug}/contacts/{$contact->id}")
        ->assertOk()
        ->assertJson(['organization' => $organization->id, 'contact' => $contact->id]);
});

it('CA-001-9: sets the context before the model binding', function (): void {
    $organization = Organization::factory()->create();
    $contact = Contact::factory()->create(['organization_id' => $organization->id]);

    $this->get("/{$organization->slug}/contacts/{$contact->id}")
        ->assertOk()
        ->assertJson(['context' => $organization->id]);
});

it('CA-001-9: an unknown slug answers 404', function (): void {
    $this->get('/no-existe/whoami')->assertNotFound();
});

it('CA-001-10: leaves the context empty after a successful request', function (): void {
    $organization = Organization::factory()->create();

    $this->get("/{$organization->slug}/whoami")->assertOk();

    expect(CurrentOrganization::has())->toBeFalse();
});

it('CA-001-10: leaves the context empty after a request that throws', function (): void {
    $organization = Organization::factory()->create();

    $this->withoutExceptionHandling();

    $thrown = null;
    try {
        $this->get("/{$organization->slug}/boom");
    } catch (RuntimeException $exception) {
        $thrown = $exception;
    }

    expect($thrown?->getMessage())->toBe('boom')
        ->and(CurrentOrganization::has())->toBeFalse();
});

it('CA-001-10: the next request does not inherit the previous organization', function (): void {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $this->get("/{$organizationA->slug}/whoami")->assertJson(['context' => $organizationA->id]);
    $this->get("/{$organizationB->slug}/whoami")->assertJson(['context' => $organizationB->id]);
});

it('CA-001-9: a suspended organization answers 404 and leaves no context', function (): void {
    $organization = Organization::factory()->create(['status' => OrganizationStatus::Suspended]);

    $this->get("/{$organization->slug}/whoami")->assertNotFound();

    expect(CurrentOrganization::has())->toBeFalse();
});

it('CA-001-9: a soft-deleted organization answers 404', function (): void {
    $organization = Organization::factory()->create();
    $organization->delete();

    $this->get("/{$organization->slug}/whoami")->assertNotFound();
});
