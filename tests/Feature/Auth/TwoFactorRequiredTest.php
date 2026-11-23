<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Exceptions\Domain\TwoFactorCannotBeDisabled;
use App\Filament\Pages\Auth\Login;
use App\Filament\Resources\TeamMembers\TeamMemberResource;
use App\Models\User;
use App\Notifications\TwoFactorEnabledNotification;
use App\Support\Auth\AccessThrottle;
use App\Support\Auth\PortalDestination;
use App\Support\Auth\TotpAuthentication;
use App\Support\Auth\TwoFactorRequirement;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Memberships;
use Tests\Support\TwoFactor;

uses(RefreshDatabase::class);

const TWO_FACTOR_PASSWORD = 'correct-horse-battery';

beforeEach(function (): void {
    config(['two_factor.required' => true]);
    Filament::setCurrentPanel('admin');
    RateLimiter::clear('two-factor-test');
});

function twoFactorMember(OrganizationRole $role = OrganizationRole::OrgAdmin, string $email = 'ana@example.test'): User
{
    return Memberships::attach(
        User::factory()->create(['email' => $email, 'password' => TWO_FACTOR_PASSWORD]),
        Memberships::organization(),
        $role,
    );
}

/** @return Testable<Component> */
function passwordStep(string $email = 'ana@example.test'): Testable
{
    return Livewire::test(Login::class)
        ->fillForm(['email' => $email, 'password' => TWO_FACTOR_PASSWORD])
        ->call('authenticate');
}

function setUpUrl(string $panel): string
{
    return (string) Filament::getPanel($panel)->getSetUpRequiredMultiFactorAuthenticationUrl();
}

/* ------------------------------------------------------------------ CA-014-1 */

it('CA-014-1: a member without 2FA is sent to set it up and sees no page of the panel until they confirm it', function (OrganizationRole $role): void {
    $user = twoFactorMember($role);
    $organization = $user->organizations()->sole();

    $this->actingAs($user)->get(Filament::getPanel('admin')->getUrl($organization))->assertRedirect(setUpUrl('admin'));
    $this->actingAs($user)->get(TeamMemberResource::getUrl('index', tenant: $organization))->assertRedirect(setUpUrl('admin'));

    TwoFactor::enable($user);

    $this->actingAs($user->fresh())->get(Filament::getPanel('admin')->getUrl($organization))->assertOk();
})->with([
    'org admin' => [OrganizationRole::OrgAdmin],
    'organizer' => [OrganizationRole::Organizer],
]);

it('CA-014-1: a platform user without 2FA is sent to set it up in the platform panel', function (): void {
    $platform = User::factory()->platformAdmin()->create();

    $this->actingAs($platform)->get('/platform')->assertRedirect(setUpUrl('platform'));

    TwoFactor::enable($platform);

    $this->actingAs($platform->fresh())->get('/platform')->assertOk();
});

it('CA-014-1: after signing in with a password, the first page is the 2FA setup', function (): void {
    $user = twoFactorMember();

    passwordStep()->assertHasNoFormErrors();
    expect(Auth::check())->toBeTrue();

    $this->get(PortalDestination::home($user))->assertRedirect(setUpUrl('admin'));
});

it('CA-014-1: the setup page itself can be opened without 2FA, so nobody is locked in a redirect loop', function (): void {
    $this->actingAs(twoFactorMember())->get(setUpUrl('admin'))->assertOk();
});

it('CA-014-1: the rule covers the reviewer role, ready for when that panel exists, and exempts people without a covered role', function (): void {
    $reviewer = twoFactorMember(OrganizationRole::Reviewer, 'rev@example.test');
    $nobody = User::factory()->create();

    expect($reviewer->requiresTwoFactor())->toBeTrue()
        ->and(TwoFactorRequirement::isSatisfiedBy($reviewer))->toBeFalse()
        ->and($nobody->requiresTwoFactor())->toBeFalse()
        ->and(TwoFactorRequirement::isSatisfiedBy($nobody))->toBeTrue();

    TwoFactor::enable($reviewer);

    expect(TwoFactorRequirement::isSatisfiedBy($reviewer->fresh()))->toBeTrue();
});

it('CA-014-1: with the requirement switched off no panel asks for it and the contract reports it', function (): void {
    config(['two_factor.required' => false]);
    $user = twoFactorMember();

    $this->actingAs($user)->get(Filament::getPanel('admin')->getUrl($user->organizations()->sole()))->assertOk();
    expect(TwoFactorRequirement::isEnforced())->toBeFalse()
        ->and(TwoFactorRequirement::isSatisfiedBy($user))->toBeTrue();
});

it('CA-014-1: confirming the setup stores the secret encrypted and the recovery codes hashed, and notifies the owner', function (): void {
    Notification::fake();
    $user = twoFactorMember();
    $provider = TotpAuthentication::configured();

    $secret = 'JBSWY3DPEHPK3PXP';
    $provider->saveSecret($user, $secret);
    $provider->saveRecoveryCodes($user, ['PLAIN-CODE-1', 'PLAIN-CODE-2']);

    $stored = DB::table('users')->where('id', $user->id)->first();

    expect($user->fresh()->hasConfirmedTwoFactor())->toBeTrue()
        ->and($stored->app_authentication_secret)->not->toContain($secret)
        ->and($stored->app_authentication_recovery_codes)->not->toContain('PLAIN-CODE')
        ->and(collect($user->fresh()->getAppAuthenticationRecoveryCodes())->every(fn (string $hash): bool => Hash::info($hash)['algoName'] !== 'unknown'))->toBeTrue();
    Notification::assertSentTo($user, TwoFactorEnabledNotification::class);
});

/* ------------------------------------------------------------------ CA-014-2 */

it('CA-014-2: with 2FA active the password alone does not sign in; the code is asked next', function (): void {
    $user = twoFactorMember();
    TwoFactor::enable($user);

    $component = passwordStep();

    expect(Auth::check())->toBeFalse()
        ->and($component->get('userUndertakingMultiFactorAuthentication'))->not->toBeEmpty();
});

it('CA-014-2: a valid code signs in', function (): void {
    $secret = TwoFactor::enable(twoFactorMember());

    passwordStep()
        ->set('data.multiFactor.app.code', TwoFactor::code($secret))
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(Auth::check())->toBeTrue();
});

it('CA-014-2: an invalid code is rejected and does not sign in', function (): void {
    TwoFactor::enable(twoFactorMember());

    passwordStep()
        ->set('data.multiFactor.app.code', '000000')
        ->call('authenticate')
        ->assertHasErrors();

    expect(Auth::check())->toBeFalse();
});

it('CA-014-2: a code that was already used is rejected', function (): void {
    $secret = TwoFactor::enable(twoFactorMember());
    $code = TwoFactor::code($secret);

    passwordStep()->set('data.multiFactor.app.code', $code)->call('authenticate')->assertHasNoFormErrors();
    Auth::logout();

    passwordStep()->set('data.multiFactor.app.code', $code)->call('authenticate')->assertHasErrors();

    expect(Auth::check())->toBeFalse();
});

it('CA-014-2: every code attempt counts for the sign-in attempt limit', function (): void {
    TwoFactor::enable(twoFactorMember());
    $bucket = 'login:'.hash('sha256', AccessThrottle::emailHash('ana@example.test').'|'.request()->ip());

    $component = passwordStep();
    expect(RateLimiter::attempts($bucket))->toBe(1);

    $component->set('data.multiFactor.app.code', '000000')->call('authenticate');
    $component->set('data.multiFactor.app.code', '111111')->call('authenticate');

    expect(RateLimiter::attempts($bucket))->toBe(3);

    foreach (range(1, AccessThrottle::MAX_ATTEMPTS) as $ignored) {
        try {
            $component->set('data.multiFactor.app.code', '222222')->call('authenticate');
        } catch (Throwable) {
            // El intento que rebasa el límite responde 429; también se cuenta.
        }
    }

    expect(RateLimiter::attempts($bucket))->toBeGreaterThan(AccessThrottle::MAX_ATTEMPTS);
});

/* ------------------------------------------------------------------ CA-014-3 */

it('CA-014-3: a recovery code signs in once and then cannot be used again', function (): void {
    TwoFactor::enable($user = twoFactorMember());
    $code = TwoFactor::RECOVERY_CODES[0];

    passwordStep()
        ->set('data.multiFactor.app.useRecoveryCode', true)
        ->set('data.multiFactor.app.recoveryCode', $code)
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(Auth::check())->toBeTrue()
        ->and($user->fresh()->getAppAuthenticationRecoveryCodes())->toHaveCount(count(TwoFactor::RECOVERY_CODES) - 1);

    Auth::logout();

    passwordStep()
        ->set('data.multiFactor.app.useRecoveryCode', true)
        ->set('data.multiFactor.app.recoveryCode', $code)
        ->call('authenticate')
        ->assertHasErrors();

    expect(Auth::check())->toBeFalse();
});

it('CA-014-3: an unknown recovery code is rejected', function (): void {
    TwoFactor::enable(twoFactorMember());

    passwordStep()
        ->set('data.multiFactor.app.useRecoveryCode', true)
        ->set('data.multiFactor.app.recoveryCode', 'ZZZZZZZZZZ-9999999999')
        ->call('authenticate')
        ->assertHasErrors();

    expect(Auth::check())->toBeFalse();
});

/* ------------------------------------------------------------------ CA-014-4 (propia cuenta) */

it('CA-014-4: nobody can turn off their own 2FA: the model refuses it and the panel offers no disable action', function (): void {
    $user = twoFactorMember();
    TwoFactor::enable($user);
    Auth::login($user);

    expect(fn () => $user->saveAppAuthenticationSecret(null))->toThrow(TwoFactorCannotBeDisabled::class)
        ->and($user->fresh()->hasConfirmedTwoFactor())->toBeTrue();

    $names = collect(TotpAuthentication::configured()->getActions())->map(fn (Action $action): string => (string) $action->getName());

    expect($names->contains(fn (string $name): bool => str_contains(strtolower($name), 'disable')))->toBeFalse();
});
