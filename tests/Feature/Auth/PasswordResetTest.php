<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Filament\Pages\Auth\ResetPassword;
use App\Models\User;
use App\Notifications\PasswordResetNotification;
use Filament\Facades\Filament;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Livewire\Component;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

const OLD_PASSWORD = 'old-password-for-tests';
const NEW_PASSWORD = 'a-brand-new-passphrase';

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    Http::preventStrayRequests();
    $GLOBALS['pwned_compromised'] = null;

    // Un solo fake cuya respuesta se decide en cada llamada: `Http::fake()` repetido conserva el primero.
    Http::fake(['api.pwnedpasswords.com/*' => function (): PromiseInterface|Response {
        $compromised = $GLOBALS['pwned_compromised'];

        return Http::response($compromised === null
            ? '0000000000000000000000000000000000A:1'
            : substr(strtoupper(sha1($compromised)), 5).':9999');
    }]);
});

/** Simula el servicio de contraseñas expuestas: `null` = ninguna expuesta; una cadena = esa contraseña lo está. */
function pwnedPasswordsAnswer(?string $compromised): void
{
    $GLOBALS['pwned_compromised'] = $compromised;
}

function resetUser(string $email = 'ana@example.test'): User
{
    return Memberships::attach(
        User::factory()->create(['email' => $email, 'password' => OLD_PASSWORD]),
        Memberships::organization(),
        OrganizationRole::OrgAdmin,
    );
}

/** @return Testable<Component> */
function requestReset(string $email): Testable
{
    return Livewire::test(RequestPasswordReset::class)
        ->fillForm(['email' => $email])
        ->call('request');
}

/** @return Testable<Component> */
function submitReset(User $user, string $token, string $password): Testable
{
    return Livewire::test(ResetPassword::class, ['email' => $user->email, 'token' => $token])
        ->fillForm(['password' => $password, 'passwordConfirmation' => $password])
        ->call('resetPassword');
}

it('CA-001-28: the response is identical whether the email exists or not', function (): void {
    Notification::fake();
    resetUser();
    $sent = __('access.reset_request.sent.title');

    requestReset('ana@example.test')->assertNotified($sent)->assertHasNoFormErrors();
    requestReset('nobody@example.test')->assertNotified($sent)->assertHasNoFormErrors();
});

it('CA-001-28: a queued auth.password_reset with a signed link is sent only to a registered email', function (): void {
    Notification::fake();
    $user = resetUser();

    requestReset('nobody@example.test');
    Notification::assertNothingSent();

    requestReset('Ana@Example.test');
    Notification::assertSentTo($user, PasswordResetNotification::class, function (PasswordResetNotification $notification): bool {
        return str_contains($notification->url, 'signature=') && str_contains($notification->url, 'token=');
    });

    expect(new PasswordResetNotification('https://example.test'))->toBeInstanceOf(ShouldQueue::class)
        ->and(PasswordResetNotification::CATALOG_KEY)->toBe('auth.password_reset')
        ->and(config('auth.passwords.users.expire'))->toBe(60);
});

it('CA-001-28: the reset link is refused when its signature is invalid', function (): void {
    $this->get(Filament::getResetPasswordUrl('any-token', resetUser()).'x')->assertForbidden();
});

it('CA-001-28: a user without panel access gets the same response and no email', function (): void {
    Notification::fake();
    User::factory()->create(['email' => 'loner@example.test']);

    requestReset('loner@example.test')->assertNotified(__('access.reset_request.sent.title'));

    Notification::assertNothingSent();
});

it('CA-001-28: its texts exist in es and en', function (): void {
    foreach (['es', 'en'] as $locale) {
        foreach (['subject', 'greeting', 'line', 'action', 'expiry', 'ignore'] as $key) {
            expect(trans("access.password_reset.{$key}", [], $locale))->not->toBe("access.password_reset.{$key}");
        }
    }
});

it('CA-001-29: the sixth request for the same email in 15 minutes answers 429 and sends nothing more', function (): void {
    Notification::fake();
    $user = resetUser();

    for ($request = 1; $request <= 5; $request++) {
        requestReset('ana@example.test')->assertHasNoFormErrors();
    }
    $sentBefore = Notification::sent($user, PasswordResetNotification::class)->count();

    requestReset('ana@example.test')->assertStatus(429);

    expect(Notification::sent($user, PasswordResetNotification::class)->count())->toBe($sentBefore);
    $entry = Activity::query()->where('event', 'password_reset_throttled')->sole();
    expect(json_encode($entry->properties))->not->toContain('ana@example.test');
});

it('CA-001-29: the sixth request from the same IP with different emails answers 429', function (): void {
    Notification::fake();

    for ($request = 1; $request <= 5; $request++) {
        requestReset("person{$request}@example.test")->assertHasNoFormErrors();
    }

    requestReset('person6@example.test')->assertStatus(429);
    Notification::assertNothingSent();
});

it('CA-001-29: the limit is lifted after 15 minutes', function (): void {
    Notification::fake();
    for ($request = 1; $request <= 5; $request++) {
        requestReset('ana@example.test');
    }
    requestReset('ana@example.test')->assertStatus(429);

    $this->travel(16)->minutes();

    requestReset('ana@example.test')->assertHasNoFormErrors();
});

it('a valid link changes the password, consumes the token and is audited', function (): void {
    $user = resetUser();
    $token = Password::broker()->createToken($user);

    submitReset($user, $token, NEW_PASSWORD)->assertHasNoFormErrors();

    expect(Hash::check(NEW_PASSWORD, $user->fresh()->password))->toBeTrue();
    $entry = Activity::query()->where('event', 'password_reset')->sole();
    expect($entry->causer_id)->toBe($user->id)
        ->and(json_encode($entry->properties))->not->toContain(NEW_PASSWORD);
});

it('CA-001-30: a used link is refused with a generic message and the password does not change', function (): void {
    $user = resetUser();
    $token = Password::broker()->createToken($user);
    submitReset($user, $token, NEW_PASSWORD);

    submitReset($user, $token, 'yet-another-passphrase-1')->assertNotified(__('access.reset_invalid'));

    expect(Hash::check(NEW_PASSWORD, $user->fresh()->password))->toBeTrue();
});

it('CA-001-30: an expired link is refused with the same generic message', function (): void {
    $user = resetUser();
    $token = Password::broker()->createToken($user);

    $this->travel(61)->minutes();
    submitReset($user, $token, NEW_PASSWORD)->assertNotified(__('access.reset_invalid'));

    expect(Hash::check(OLD_PASSWORD, $user->fresh()->password))->toBeTrue();
});

it('CA-001-30: an unknown email gets the same generic message as a bad token', function (): void {
    $user = resetUser();

    Livewire::test(ResetPassword::class, ['email' => 'ghost@example.test', 'token' => 'whatever'])
        ->fillForm(['password' => NEW_PASSWORD, 'passwordConfirmation' => NEW_PASSWORD])
        ->call('resetPassword')
        ->assertNotified(__('access.reset_invalid'));
    submitReset($user, 'forged-token', NEW_PASSWORD)->assertNotified(__('access.reset_invalid'));
});

it('CA-001-31: a new password shorter than 12 characters is rejected', function (): void {
    $user = resetUser();
    $token = Password::broker()->createToken($user);

    submitReset($user, $token, 'short-pass1')->assertHasFormErrors(['password']);

    expect(Hash::check(OLD_PASSWORD, $user->fresh()->password))->toBeTrue();
});

it('CA-001-31: a password found in the exposed list is rejected', function (): void {
    pwnedPasswordsAnswer(compromised: NEW_PASSWORD);
    $user = resetUser();
    $token = Password::broker()->createToken($user);

    submitReset($user, $token, NEW_PASSWORD)->assertHasFormErrors(['password']);

    expect(Hash::check(OLD_PASSWORD, $user->fresh()->password))->toBeTrue();
});

it('CA-001-31: the application defaults require 12 characters and check exposed passwords', function (): void {
    expect(validator(['password' => 'short'], ['password' => PasswordRule::default()])->fails())->toBeTrue();

    pwnedPasswordsAnswer(compromised: NEW_PASSWORD);
    expect(validator(['password' => NEW_PASSWORD], ['password' => PasswordRule::default()])->fails())->toBeTrue();

    pwnedPasswordsAnswer(compromised: null);
    expect(validator(['password' => NEW_PASSWORD], ['password' => PasswordRule::default()])->passes())->toBeTrue();
});
