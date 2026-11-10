<?php

declare(strict_types=1);

use App\Actions\Organizations\CreateOrganization;
use App\Enums\OrganizationRole;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Models\User;
use App\Notifications\NewDeviceLoginNotification;
use App\Notifications\PasswordResetNotification;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

const PLATFORM_PASSWORD = 'correct-horse-battery';

beforeEach(function (): void {
    Filament::setCurrentPanel('platform');
    RateLimiter::clear('platform-test');
});

function platformUser(string $email = 'soporte@example.test'): User
{
    return User::factory()->platformAdmin()->create(['email' => $email, 'password' => PLATFORM_PASSWORD]);
}

it('CA-016-1: a platform user without memberships signs in to the platform panel and a new device is registered and notified', function (): void {
    Notification::fake();
    platformUser();

    Livewire::test(Login::class)
        ->fillForm(['email' => 'soporte@example.test', 'password' => PLATFORM_PASSWORD])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(auth()->check())->toBeTrue();
    Notification::assertSentTo(auth()->user(), NewDeviceLoginNotification::class);

    $this->get('/platform')->assertOk();
});

it('CA-016-2: a member of an organization without is_platform_admin gets 403 in the platform panel', function (OrganizationRole $role): void {
    $user = Memberships::attach(User::factory()->create(), Memberships::organization(), $role);

    $this->actingAs($user)->get('/platform')->assertForbidden();
})->with([OrganizationRole::OrgAdmin, OrganizationRole::Organizer, OrganizationRole::Reviewer]);

it('CA-016-2: a visitor is sent to the platform login', function (): void {
    $this->get('/platform')->assertRedirect('/platform/login');
});

it('CA-016-3: a platform user without membership still cannot open an organization panel', function (): void {
    $organization = Memberships::organization();

    $denied = $this->actingAs(platformUser())->get("/admin/{$organization->slug}");

    expect($denied->status())->toBeIn([403, 404]);
});

it('CA-016-3: a platform user who is also a member of A enters the panel of A and the platform panel', function (): void {
    $organizationA = Memberships::organization();
    $organizationB = Memberships::organization();
    $user = Memberships::attach(platformUser(), $organizationA, OrganizationRole::OrgAdmin);

    $this->actingAs($user)->get("/admin/{$organizationA->slug}")->assertOk();
    $this->actingAs($user)->get('/platform')->assertOk();
    expect($this->actingAs($user)->get("/admin/{$organizationB->slug}")->status())->toBeIn([403, 404]);
});

it('CA-016-4: the platform login refuses the sixth failed attempt of the same email and IP, with 429', function (): void {
    platformUser();

    foreach (range(1, 5) as $ignored) {
        Livewire::test(Login::class)
            ->fillForm(['email' => 'soporte@example.test', 'password' => 'wrong-password'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);
    }

    Livewire::test(Login::class)
        ->fillForm(['email' => 'soporte@example.test', 'password' => 'wrong-password'])
        ->call('authenticate')
        ->assertStatus(429);
});

it('CA-016-4: a wrong password and an unknown email give the same message in the platform login', function (): void {
    platformUser();

    $wrongPassword = Livewire::test(Login::class)->fillForm(['email' => 'soporte@example.test', 'password' => 'wrong-password'])->call('authenticate');
    $unknownEmail = Livewire::test(Login::class)->fillForm(['email' => 'nadie@example.test', 'password' => 'wrong-password'])->call('authenticate');

    expect($unknownEmail->errors()->get('data.email'))->toBe($wrongPassword->errors()->get('data.email'));
});

it('CA-016-5: a platform user asks for a password reset from the platform panel and the link points to it', function (): void {
    Notification::fake();
    $user = platformUser();

    Livewire::test(RequestPasswordReset::class)->fillForm(['email' => 'soporte@example.test'])->call('request');

    Notification::assertSentTo($user, PasswordResetNotification::class, function (PasswordResetNotification $notification) use ($user): bool {
        $url = (string) $notification->toMail($user)->actionUrl;

        return str_contains($url, '/platform/password-reset/reset');
    });
});

it('CA-016-5: an unknown email gets the same answer as a known one', function (): void {
    Notification::fake();
    $user = platformUser();

    $known = Livewire::test(RequestPasswordReset::class)->fillForm(['email' => 'soporte@example.test'])->call('request');
    $unknown = Livewire::test(RequestPasswordReset::class)->fillForm(['email' => 'nadie@example.test'])->call('request');

    expect($unknown->errors()->all())->toBe($known->errors()->all());
    Notification::assertSentToTimes($user, PasswordResetNotification::class, 1);
});

it('CA-016-6: the panel answers 404 when it is not enabled and works when it is', function (): void {
    config(['platform.enabled' => false]);
    $this->get('/platform/login')->assertNotFound();

    config(['platform.enabled' => true]);
    $this->get('/platform/login')->assertOk();
});

it('CA-016-6: it is not enabled by default outside local and testing', function (): void {
    $config = require config_path('platform.php');

    expect($config['enabled'])->toBeTrue();

    $source = (string) file_get_contents(config_path('platform.php'));
    expect($source)->toContain("in_array(env('APP_ENV'), ['local', 'testing'], true)");
});

it('CA-016-7: the slug "platform" is reserved for new organizations', function (): void {
    expect(fn () => app(CreateOrganization::class)->handle('platform', 'Plataforma', 'admin@example.test', 'Admin'))
        ->toThrow(ValidationException::class);
});
