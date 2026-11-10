<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
});

it('CA-002-18: creates a fictitious organization and a published event', function (): void {
    $this->seed(DemoSeeder::class);

    $organization = Organization::query()->where('slug', 'demo')->sole();
    $event = CurrentOrganization::run($organization, fn () => Event::query()->sole());

    expect($event->status)->toBe(EventStatus::Published)
        ->and($event->organization_id)->toBe($organization->id)
        ->and($event->slug)->toBe('congreso-demo');
});

it('CA-002-18: running it twice does not duplicate anything', function (): void {
    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class);

    $organization = Organization::query()->where('slug', 'demo')->sole();

    expect(Organization::query()->count())->toBe(1)
        ->and(CurrentOrganization::run($organization, fn () => Event::query()->count()))->toBe(1);
});

it('CA-002-18: refuses to run in production and writes nothing', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');

    // Se invoca el seeder directo: `db:seed` en producción pide confirmación y no lanza.
    expect(fn () => $this->app->make(DemoSeeder::class)->setContainer($this->app)->__invoke())
        ->toThrow(RuntimeException::class);

    expect(Organization::query()->count())->toBe(0);
});
