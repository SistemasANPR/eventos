<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Tenancy\Exceptions\MissingOrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TenantModels;

/*
 * Guardiana de aislamiento (ADR-0003).
 *
 * Contrato que asume (se implementa en el plan 001-nucleo-multitenant):
 *  - cada modelo con BelongsToOrganization tiene una fábrica que acepta
 *    `organization_id` explícito;
 *  - CurrentOrganization::set()/forget() controla el contexto;
 *  - sin contexto, cualquier consulta lanza MissingOrganizationContext.
 */

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
});

it('never returns rows from another organization', function (string $model): void {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $model::factory()->count(2)->create(['organization_id' => $organizationA->id]);
    $model::factory()->count(3)->create(['organization_id' => $organizationB->id]);

    CurrentOrganization::set($organizationA);
    expect($model::query()->count())->toBe(2)
        ->and($model::query()->pluck('organization_id')->unique()->values()->all())->toBe([$organizationA->id]);

    CurrentOrganization::set($organizationB);
    expect($model::query()->count())->toBe(3)
        ->and($model::query()->pluck('organization_id')->unique()->values()->all())->toBe([$organizationB->id]);
})->with(fn (): array => TenantModels::dataset(TenantModels::scoped()));

it('fails instead of returning data when there is no organization context', function (string $model): void {
    CurrentOrganization::forget();

    expect(fn () => $model::query()->count())->toThrow(MissingOrganizationContext::class);
})->with(fn (): array => TenantModels::dataset(TenantModels::scoped()));

it('assigns the current organization when creating a record', function (string $model): void {
    $organization = Organization::factory()->create();
    CurrentOrganization::set($organization);

    $record = $model::factory()->create();

    expect($record->organization_id)->toBe($organization->id);
})->with(fn (): array => TenantModels::dataset(TenantModels::scoped()));
