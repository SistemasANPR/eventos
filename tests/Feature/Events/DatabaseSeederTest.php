<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
});

it('refuses to run in production and writes nothing', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');

    // Se invoca el seeder directo: `db:seed` en producción pide confirmación y no lanza.
    expect(fn () => $this->app->make(DatabaseSeeder::class)->setContainer($this->app)->__invoke())
        ->toThrow(RuntimeException::class);

    expect(Organization::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(0);
});

it('seeds the demo organization locally and no longer creates test@example.com', function (): void {
    $this->seed(DatabaseSeeder::class);

    expect(Organization::query()->where('slug', 'demo')->exists())->toBeTrue()
        ->and(User::query()->where('email', 'test@example.com')->exists())->toBeFalse();
});

it('does not duplicate data when run twice', function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(Organization::query()->count())->toBe(1);
});
