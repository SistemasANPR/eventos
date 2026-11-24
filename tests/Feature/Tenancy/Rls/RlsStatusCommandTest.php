<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AppDatabaseRole;

uses(RefreshDatabase::class);

it('CA-014-10: app:rls-status reports without failing and does not require RLS to be effective by default', function (): void {
    $this->artisan('app:rls-status')
        ->expectsOutputToContain('RLS tiene efecto para este rol: NO')
        ->assertExitCode(0);
});

it('CA-014-10: app:rls-status --require-effective fails for a role that bypasses RLS and passes for the application role', function (): void {
    $this->artisan('app:rls-status', ['--require-effective' => true])->assertExitCode(1);

    $exit = AppDatabaseRole::run(fn (): int => Artisan::call('app:rls-status', ['--require-effective' => true]));

    expect($exit)->toBe(0);
});

it('CA-014-11: app:rls-status fails when a table with organization_id has no policy', function (): void {
    Schema::create('rls_status_probe', function ($table): void {
        $table->uuid('organization_id');
    });

    $this->artisan('app:rls-status')
        ->expectsOutputToContain('rls_status_probe')
        ->assertExitCode(1);
});

it('CA-014-10: the migrations connection falls back to the application credentials when it has none of its own', function (): void {
    $application = config('database.connections.pgsql');
    $migrator = config('database.connections.pgsql_migrator');

    expect($migrator['username'])->toBe($application['username'])
        ->and($migrator['password'])->toBe($application['password'])
        ->and($migrator['database'])->toBe($application['database']);
});
