<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\NewDeviceLoginNotification;
use App\Notifications\PasswordResetNotification;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Component;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

const UNIFIED_PASSWORD = 'correct-horse-battery';

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

function memberUser(string $email = 'ana@example.test', ?Organization $organization = null): User
{
    return Memberships::attach(
        User::factory()->create(['email' => $email, 'password' => UNIFIED_PASSWORD]),
        $organization ?? Memberships::organization(),
        OrganizationRole::OrgAdmin,
    );
}

function platformOnlyUser(string $email = 'soporte@example.test'): User
{
    return User::factory()->platformAdmin()->create(['email' => $email, 'password' => UNIFIED_PASSWORD]);
}

/** @return Testable<Component> */
function unifiedLogin(string $email, string $password = UNIFIED_PASSWORD): Testable
{
    return Livewire::test(Login::class)->fillForm(['email' => $email, 'password' => $password])->call('authenticate');
}

/** @return list<string> nombres de los elementos visibles del menú de usuario de un panel, para el usuario en sesión */
function userMenuNames(string $panelId): array
{
    return collect(Filament::getPanel($panelId)->getUserMenuItems())
        ->map(fn (Action $item): string => (string) $item->getName())
        ->values()
        ->all();
}

it('CA-017-1: a member of an active organization lands on the panel of their organization, not on the platform', function (): void {
    $organization = Memberships::organization();
    memberUser(organization: $organization);

    unifiedLogin('ana@example.test')
        ->assertHasNoFormErrors()
        ->assertRedirectContains("/admin/{$organization->slug}");
});

it('CA-017-2: a platform user without memberships signs in at the single login and lands on the platform panel', function (): void {
    platformOnlyUser();

    $login = unifiedLogin('soporte@example.test')->assertHasNoFormErrors();

    expect(auth()->check())->toBeTrue();
    $login->assertRedirectContains('/platform');
});

it('CA-017-3: someone with access to both panels lands on the platform and sees the link to the other panel in each one', function (): void {
    $organization = Memberships::organization();
    $user = Memberships::attach(platformOnlyUser('doble@example.test'), $organization, OrganizationRole::OrgAdmin);

    unifiedLogin('doble@example.test')->assertRedirectContains('/platform');

    $this->actingAs($user);
    expect(userMenuNames('admin'))->toContain('switch-to-platform')
        ->and(userMenuNames('platform'))->toContain('switch-to-organization');
});

it('CA-017-4: someone who can only enter one panel does not see a link to the other', function (): void {
    $member = memberUser();
    $this->actingAs($member);
    expect(userMenuNames('admin'))->not->toContain('switch-to-platform');

    $platform = platformOnlyUser();
    $this->actingAs($platform);
    expect(userMenuNames('platform'))->not->toContain('switch-to-organization');
});

it('CA-017-5: with the platform panel off, a platform-only user gets the wrong-password message and no session', function (): void {
    config(['platform.enabled' => false]);
    platformOnlyUser();
    memberUser('otra@example.test');

    $platformOnly = unifiedLogin('soporte@example.test')->assertHasFormErrors(['email']);
    $wrongPassword = unifiedLogin('otra@example.test', 'not-the-password');

    expect(auth()->check())->toBeFalse()
        ->and($platformOnly->errors()->get('data.email'))->toBe($wrongPassword->errors()->get('data.email'));
});

it('CA-017-5: with the platform panel off, someone who is also a member goes to their organization panel', function (): void {
    config(['platform.enabled' => false]);
    $organization = Memberships::organization();
    Memberships::attach(platformOnlyUser('doble@example.test'), $organization, OrganizationRole::OrgAdmin);

    unifiedLogin('doble@example.test')->assertRedirectContains("/admin/{$organization->slug}");
});

it('CA-017-6: a user with no access to any panel gets the same message as a wrong password', function (): void {
    User::factory()->create(['email' => 'loner@example.test', 'password' => UNIFIED_PASSWORD]);
    $suspended = Organization::factory()->create(['status' => OrganizationStatus::Suspended]);
    Memberships::attach(User::factory()->create(['email' => 'suspendida@example.test', 'password' => UNIFIED_PASSWORD]), $suspended);
    memberUser();

    $wrongPassword = unifiedLogin('ana@example.test', 'not-the-password');

    foreach (['loner@example.test', 'suspendida@example.test'] as $email) {
        $denied = unifiedLogin($email)->assertHasFormErrors(['email']);
        expect(auth()->check())->toBeFalse()
            ->and($denied->errors()->get('data.email'))->toBe($wrongPassword->errors()->get('data.email'));
    }
});

it('CA-017-7: the page a platform user was heading to is respected, but an organizer is never sent to a 403', function (): void {
    $organization = Memberships::organization();
    memberUser(organization: $organization);
    platformOnlyUser();

    session(['url.intended' => 'http://localhost/platform/organizations']);
    unifiedLogin('soporte@example.test')->assertRedirectContains('/platform/organizations');

    auth()->logout();
    session(['url.intended' => 'http://localhost/platform']);
    unifiedLogin('ana@example.test')->assertRedirectContains("/admin/{$organization->slug}");
});

it('CA-017-8: an intended URL to another site, to a scheme that is not http or to a route that is not a panel is ignored', function (string $intended): void {
    $organization = Memberships::organization();
    memberUser(organization: $organization);

    session(['url.intended' => $intended]);

    unifiedLogin('ana@example.test')->assertRedirectContains("/admin/{$organization->slug}");
})->with([
    'another domain' => ['https://evil.example/admin'],
    'protocol-relative' => ['//evil.example/admin'],
    'javascript scheme' => ['javascript:alert(1)'],
    'route that is not a panel' => ['http://localhost/no-existe'],
    'backslash trick' => ['/\\evil.example'],
]);

it('CA-017-9: /platform/login redirects a visitor to the single login and sends each signed-in user to their portal', function (): void {
    $organization = Memberships::organization();
    $member = memberUser(organization: $organization);
    $platform = platformOnlyUser();

    $this->get('/platform/login')->assertRedirect('/admin/login');

    $this->actingAs($platform)->get('/platform/login')->assertRedirect(Filament::getPanel('platform')->getUrl());
    $this->flushSession();
    $this->actingAs($member)->get('/platform/login')->assertRedirectContains("/admin/{$organization->slug}");
});

it('CA-017-9: the single login also sends a signed-in user to their portal and signs out one with no access', function (): void {
    $organization = Memberships::organization();
    $member = memberUser(organization: $organization);
    $platform = platformOnlyUser();

    $this->actingAs($platform)->get('/admin/login')->assertRedirect(Filament::getPanel('platform')->getUrl());
    $this->flushSession();
    $this->actingAs($member)->get('/admin/login')->assertRedirectContains("/admin/{$organization->slug}");

    $loner = User::factory()->create();
    $this->flushSession();
    $this->actingAs($loner)->get('/admin/login')->assertOk();
    $this->assertGuest();
});

it('CA-017-10: the single login keeps the limit of five attempts, answering 429 on the sixth, for a platform user too', function (): void {
    platformOnlyUser();

    foreach (range(1, 5) as $ignored) {
        unifiedLogin('soporte@example.test', 'wrong-password')->assertHasFormErrors(['email']);
    }

    unifiedLogin('soporte@example.test', 'wrong-password')->assertStatus(429);
});

it('CA-017-10: signing in regenerates the session id', function (): void {
    platformOnlyUser();
    $before = session()->getId();

    unifiedLogin('soporte@example.test')->assertHasNoFormErrors();

    expect(session()->getId())->not->toBe($before);
});

it('CA-017-11: a platform user signing in from a new device is registered and notified, like an organization member', function (): void {
    Notification::fake();
    platformOnlyUser();

    unifiedLogin('soporte@example.test')->assertHasNoFormErrors();

    Notification::assertSentTo(auth()->user(), NewDeviceLoginNotification::class);
});

it('CA-017-12: the reset link of a platform user points to the single panel, and the platform has no reset routes of its own', function (): void {
    Notification::fake();
    $user = platformOnlyUser();

    Livewire::test(RequestPasswordReset::class)->fillForm(['email' => 'soporte@example.test'])->call('request');

    Notification::assertSentTo($user, PasswordResetNotification::class, function (PasswordResetNotification $notification) use ($user): bool {
        $url = (string) $notification->toMail($user)->actionUrl;

        return str_contains($url, '/admin/password-reset/reset') && ! str_contains($url, '/platform/');
    });
    $this->get('/platform/password-reset/request')->assertNotFound();
});

it('CA-017-13: signing in at the single login does not open the platform panel to an organizer', function (): void {
    $member = memberUser();

    $this->actingAs($member)->get('/platform')->assertForbidden();
    $this->actingAs($member)->get('/platform/organizations')->assertForbidden();
});
