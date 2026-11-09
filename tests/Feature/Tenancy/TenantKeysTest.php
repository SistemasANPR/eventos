<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Tenancy\Exceptions\MissingOrganizationContext;
use App\Support\Tenancy\TenantCacheKey;
use App\Support\Tenancy\TenantStoragePath;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
});

it('CA-001-8: the cache key includes the organization', function (): void {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    CurrentOrganization::set($organizationA);
    $keyA = TenantCacheKey::for('contacts.list');
    CurrentOrganization::set($organizationB);
    $keyB = TenantCacheKey::for('contacts.list');

    expect($keyA)->toBe("organization:{$organizationA->id}:contacts.list")
        ->and($keyB)->toBe("organization:{$organizationB->id}:contacts.list")
        ->and($keyA)->not->toBe($keyB);
});

it('CA-001-8: the storage path includes the organization', function (): void {
    $organization = Organization::factory()->create();
    CurrentOrganization::set($organization);

    expect(TenantStoragePath::for('submissions/abc.pdf'))
        ->toBe("organizations/{$organization->id}/submissions/abc.pdf");
});

it('CA-001-8: both throw without organization context', function (): void {
    expect(fn () => TenantCacheKey::for('contacts.list'))->toThrow(MissingOrganizationContext::class)
        ->and(fn () => TenantStoragePath::for('submissions/abc.pdf'))->toThrow(MissingOrganizationContext::class);
});

it('CA-001-8: the storage path refuses to leave the organization directory', function (string $path): void {
    CurrentOrganization::set(Organization::factory()->create());

    expect(fn () => TenantStoragePath::for($path))->toThrow(InvalidArgumentException::class);
})->with(['parent' => '../other/file.pdf', 'nested parent' => 'a/../../b', 'absolute' => '/etc/passwd']);
