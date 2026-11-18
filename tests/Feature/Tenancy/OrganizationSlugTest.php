<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Rules\OrganizationSlug;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

it('CA-001-6: accepts a new valid slug', function (string $slug): void {
    $validator = Validator::make(['slug' => $slug], ['slug' => ['required', new OrganizationSlug]]);

    expect($validator->passes())->toBeTrue();
})->with(['anpr', 'congreso-2026', 'a1b']);

it('CA-001-6: rejects a slug with an invalid format', function (string $slug): void {
    $validator = Validator::make(['slug' => $slug], ['slug' => ['required', new OrganizationSlug]]);

    expect($validator->fails())->toBeTrue();
})->with([
    'uppercase' => 'ANPR',
    'spaces' => 'mi organizacion',
    'underscore' => 'mi_org',
    'leading hyphen' => '-anpr',
    'trailing hyphen' => 'anpr-',
    'double hyphen' => 'a--b',
    'too short' => 'ab',
    'too long' => str_repeat('a', 64),
    'accent' => 'organización',
    'empty' => '',
    'trailing newline' => "anpr\n",
]);

it('CA-001-6: rejects a reserved slug', function (string $slug): void {
    $validator = Validator::make(['slug' => $slug], ['slug' => ['required', new OrganizationSlug]]);

    expect($validator->fails())->toBeTrue();
})->with([
    'admin', 'up', 'login', 'register', 'api',
    'livewire', 'horizon', 'filament', 'storage', 'build', 'logout', 'password',
]);

it('CA-001-6: rejects a slug that is already used', function (): void {
    Organization::factory()->create(['slug' => 'anpr']);

    expect(fn () => Organization::factory()->create(['slug' => 'anpr']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('CA-001-6: the database refuses a slug with an invalid format', function (): void {
    expect(fn () => Organization::factory()->create(['slug' => 'Not Valid']))
        ->toThrow(QueryException::class);
});
