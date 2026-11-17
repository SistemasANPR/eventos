<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
});

it('CA-001-12: sets the context while the callback runs and clears it after', function (): void {
    $organization = Organization::factory()->create();

    $inside = CurrentOrganization::run($organization, fn (): string => CurrentOrganization::id());

    expect($inside)->toBe($organization->id)
        ->and(CurrentOrganization::has())->toBeFalse();
});

it('CA-001-12: restores the previous context after the callback', function (): void {
    $outer = Organization::factory()->create();
    $inner = Organization::factory()->create();
    CurrentOrganization::set($outer);

    CurrentOrganization::run($inner, fn (): string => CurrentOrganization::id());

    expect(CurrentOrganization::id())->toBe($outer->id);
});

it('CA-001-12: restores the context even when the callback throws', function (): void {
    $outer = Organization::factory()->create();
    $inner = Organization::factory()->create();
    CurrentOrganization::set($outer);

    expect(fn () => CurrentOrganization::run($inner, fn () => throw new RuntimeException('boom')))
        ->toThrow(RuntimeException::class, 'boom')
        ->and(CurrentOrganization::id())->toBe($outer->id);
});

it('CA-001-12: keeps the permissions team in sync with the context', function (): void {
    $organization = Organization::factory()->create();
    $registrar = app(PermissionRegistrar::class);

    CurrentOrganization::set($organization);
    expect($registrar->getPermissionsTeamId())->toBe($organization->id);

    CurrentOrganization::forget();
    expect($registrar->getPermissionsTeamId())->toBeNull();
});
