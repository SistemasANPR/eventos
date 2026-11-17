<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Tenancy\Exceptions\MissingOrganizationContext;
use App\Support\Tenancy\Exceptions\OrganizationMismatch;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
});

it('CA-001-1: returns only the records of the current organization', function (): void {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();
    Contact::factory()->count(2)->create(['organization_id' => $organizationA->id]);
    Contact::factory()->count(3)->create(['organization_id' => $organizationB->id]);

    CurrentOrganization::set($organizationA);

    expect(Contact::query()->count())->toBe(2)
        ->and(Contact::query()->pluck('organization_id')->unique()->all())->toBe([$organizationA->id]);
});

it('CA-001-2: throws instead of returning data when there is no organization context', function (): void {
    Contact::factory()->create();

    expect(fn () => Contact::query()->get())->toThrow(MissingOrganizationContext::class)
        ->and(fn () => Contact::query()->count())->toThrow(MissingOrganizationContext::class);
});

it('CA-001-3: assigns the current organization when the record has none', function (): void {
    $organization = Organization::factory()->create();
    CurrentOrganization::set($organization);

    $contact = new Contact;
    $contact->full_name = 'Ana Pérez';
    $contact->email = 'ana@example.test';
    $contact->save();

    expect($contact->organization_id)->toBe($organization->id);
});

it('CA-001-3: refuses to create a record without organization and without context', function (): void {
    $contact = new Contact;
    $contact->full_name = 'Ana Pérez';
    $contact->email = 'ana@example.test';

    expect(fn () => $contact->save())->toThrow(MissingOrganizationContext::class);
});

it('CA-001-4: rejects creating a record for another organization', function (): void {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();
    CurrentOrganization::set($organizationA);

    $contact = new Contact;
    $contact->organization_id = $organizationB->id;
    $contact->full_name = 'Ana Pérez';
    $contact->email = 'ana@example.test';

    expect(fn () => $contact->save())->toThrow(OrganizationMismatch::class);

    CurrentOrganization::forget();
    expect(Contact::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('CA-001-4: rejects moving a record to another organization', function (): void {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();
    CurrentOrganization::set($organizationA);
    $contact = Contact::factory()->create();

    $contact->organization_id = $organizationB->id;

    expect(fn () => $contact->save())->toThrow(OrganizationMismatch::class);
});

it('CA-001-4: does not accept organization_id through mass assignment', function (): void {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();
    CurrentOrganization::set($organizationA);

    expect(fn () => Contact::query()->create([
        'organization_id' => $organizationB->id,
        'full_name' => 'Ana Pérez',
        'email' => 'ana@example.test',
    ]))->toThrow(MassAssignmentException::class);
});
