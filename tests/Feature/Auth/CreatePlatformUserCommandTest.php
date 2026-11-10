<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\SetPasswordNotification;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

it('CA-001-16: creates a platform user and queues the set-password notification', function (): void {
    Notification::fake();

    $this->artisan('app:create-platform-user', ['email' => 'plataforma@example.test', 'name' => 'Plataforma'])
        ->assertSuccessful();

    $user = User::query()->where('email', 'plataforma@example.test')->sole();
    expect($user->is_platform_admin)->toBeTrue()
        ->and(Activity::query()->where('event', 'platform_user_created')->count())->toBe(1);
    Notification::assertSentTo($user, SetPasswordNotification::class);
});

it('CA-001-16: rejects an invalid email and creates nothing', function (): void {
    Notification::fake();

    $this->artisan('app:create-platform-user', ['email' => 'no-es-correo', 'name' => 'X'])->assertFailed();

    expect(User::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('CA-001-16: refuses to promote an existing user by email', function (): void {
    Notification::fake();
    $existing = User::factory()->create(['email' => 'existe@example.test']);

    $this->artisan('app:create-platform-user', ['email' => 'existe@example.test', 'name' => 'X'])->assertFailed();

    expect($existing->refresh()->is_platform_admin)->toBeFalse();
    Notification::assertNothingSent();
});

it('CA-001-16: is_platform_admin is not mass assignable', function (): void {
    expect(fn () => new User(['name' => 'A', 'email' => 'a@example.test', 'password' => 'x', 'is_platform_admin' => true]))
        ->toThrow(MassAssignmentException::class)
        ->and((new User)->getFillable())->not->toContain('is_platform_admin');
});

it('CA-001-16: no HTTP request can set is_platform_admin', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/admin/profile', ['is_platform_admin' => true]);
    $this->actingAs($user)->patch('/admin/profile', ['is_platform_admin' => true]);

    expect($user->refresh()->is_platform_admin)->toBeFalse();
});
