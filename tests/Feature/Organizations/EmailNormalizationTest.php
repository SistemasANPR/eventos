<?php

declare(strict_types=1);

use App\Actions\Organizations\CreateOrganization;
use App\Actions\Organizations\CreatePlatformUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();
});

it('CA-001-14: stores the first administrator email in lowercase and without spaces', function (): void {
    app(CreateOrganization::class)->handle('anpr', 'ANPR', '  Admin@Example.TEST ', 'Ana Admin');

    expect(User::query()->where('email', 'admin@example.test')->exists())->toBeTrue()
        ->and(User::query()->count())->toBe(1);
});

it('CA-001-14: reuses the existing user whatever the case of the email', function (): void {
    User::factory()->create(['email' => 'admin@example.test']);

    app(CreateOrganization::class)->handle('anpr', 'ANPR', 'ADMIN@example.test', 'Ana Admin');

    expect(User::query()->count())->toBe(1);
});

it('CA-001-16: stores the platform user email in lowercase', function (): void {
    $user = app(CreatePlatformUser::class)->handle(' Ops@Example.TEST ', 'Ops');

    expect($user->email)->toBe('ops@example.test');
});

it('CA-001-16: refuses a platform user whose email only differs in case from an existing one', function (): void {
    User::factory()->create(['email' => 'ops@example.test']);

    expect(fn () => app(CreatePlatformUser::class)->handle('OPS@example.test', 'Ops'))
        ->toThrow(ValidationException::class);

    expect(User::query()->count())->toBe(1);
});
