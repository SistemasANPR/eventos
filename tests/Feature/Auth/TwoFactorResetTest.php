<?php

declare(strict_types=1);

use App\Actions\Auth\ResetTwoFactor;
use App\Actions\Auth\ResetTwoFactorByPlatform;
use App\Enums\OrganizationRole;
use App\Filament\Platform\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\TeamMembers\Pages\ListTeamMembers;
use App\Models\Activity;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\TwoFactorResetNotification;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Memberships;
use Tests\Support\TwoFactor;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();
});

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/** @return array{0: Organization, 1: User, 2: User} organización, administrador y un integrante, ambos con 2FA */
function organizationWithTwoFactor(OrganizationRole $memberRole = OrganizationRole::Organizer): array
{
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $member = Memberships::attach(User::factory()->create(), $organization, $memberRole);
    TwoFactor::enable($admin);
    TwoFactor::enable($member);

    return [$organization, $admin->fresh(), $member->fresh()];
}

/** @return array<string, string> */
function verification(): array
{
    return ['verification_channel' => 'phone_call', 'verification_note' => 'Llamada al telefono registrado de la direccion, confirmado por Luis.'];
}

/* ------------------------------------------------------------------ CA-014-4 */

it('CA-014-4: another administrator with two_factor.reset resets the 2FA with an audit record and an email to the owner', function (): void {
    [$organization, $admin, $member] = organizationWithTwoFactor();

    app(ResetTwoFactor::class)->handle($admin, $organization, $member);

    $member->refresh();
    expect($member->hasConfirmedTwoFactor())->toBeFalse()
        ->and($member->app_authentication_secret)->toBeNull()
        ->and($member->app_authentication_recovery_codes)->toBeNull();

    $entry = Activity::query()->where('event', 'two_factor_reset')->sole();
    expect($entry->causer_id)->toBe($admin->id)
        ->and($entry->subject_id)->toBe($member->id)
        ->and($entry->organization_id)->toBe($organization->id)
        ->and(json_encode($entry->properties))->not->toContain('secret');
    Notification::assertSentTo($member, TwoFactorResetNotification::class);
});

it('CA-014-4: nobody resets their own 2FA through the reset action', function (): void {
    [$organization, $admin] = organizationWithTwoFactor();

    expect(fn () => app(ResetTwoFactor::class)->handle($admin, $organization, $admin))->toThrow(AuthorizationException::class);

    expect($admin->fresh()->hasConfirmedTwoFactor())->toBeTrue();
    Notification::assertNothingSent();
    expect(Activity::query()->where('event', 'two_factor_reset')->count())->toBe(0);
});

it('CA-014-4: whoever lacks two_factor.reset cannot reset it, and an administrator of another organization cannot either', function (): void {
    [$organization, , $member] = organizationWithTwoFactor();
    $organizer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer);
    $reviewer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);
    $outsider = Memberships::attach(User::factory()->create(), Memberships::organization(), OrganizationRole::OrgAdmin);

    foreach ([$organizer, $reviewer, $outsider] as $actor) {
        expect(fn () => app(ResetTwoFactor::class)->handle($actor, $organization, $member))->toThrow(AuthorizationException::class);
    }

    expect($member->fresh()->hasConfirmedTwoFactor())->toBeTrue();
    Notification::assertNothingSent();
});

it('CA-014-4: an administrator cannot reset a person who is not an active member of the organization', function (): void {
    [$organization, $admin] = organizationWithTwoFactor();
    $stranger = Memberships::attach(User::factory()->create(), Memberships::organization(), OrganizationRole::Organizer);
    TwoFactor::enable($stranger);

    expect(fn () => app(ResetTwoFactor::class)->handle($admin, $organization, $stranger))->toThrow(AuthorizationException::class);

    expect($stranger->fresh()->hasConfirmedTwoFactor())->toBeTrue();
});

it('CA-014-4: the team table offers the reset to an administrator, only for a member who has 2FA, and runs it', function (): void {
    [$organization, $admin, $member] = organizationWithTwoFactor();
    $without = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer);

    Filament::setCurrentPanel('admin');
    Auth::shouldUse('web');
    Auth::guard('web')->setUser($admin);
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    Livewire::test(ListTeamMembers::class)
        ->assertTableActionVisible('resetTwoFactor', $member)
        ->assertTableActionHidden('resetTwoFactor', $without)
        ->assertTableActionHidden('resetTwoFactor', $admin)
        ->callTableAction('resetTwoFactor', $member)
        ->assertNotified(__('team.reset_two_factor.done'));

    expect($member->fresh()->hasConfirmedTwoFactor())->toBeFalse();
});

it('CA-014-4: the permission is only the administrator\'s', function (): void {
    [$organization, $admin] = organizationWithTwoFactor();
    $organizer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer);

    CurrentOrganization::run($organization, function () use ($admin, $organizer): void {
        expect($admin->can('two_factor.reset'))->toBeTrue()
            ->and($organizer->can('two_factor.reset'))->toBeFalse();
    });
});

/* ------------------------------------------------------------------ CA-014-5 */

it('CA-014-5: with no other administrator, platform resets the 2FA after recording the out-of-band verification', function (): void {
    $organization = Memberships::organization();
    $onlyAdmin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    TwoFactor::enable($onlyAdmin);
    $platform = User::factory()->platformAdmin()->create();

    app(ResetTwoFactorByPlatform::class)->handle($platform, $onlyAdmin, verification());

    expect($onlyAdmin->fresh()->hasConfirmedTwoFactor())->toBeFalse();
    $entry = Activity::query()->where('event', 'two_factor_reset')->sole();
    expect($entry->causer_id)->toBe($platform->id)
        ->and($entry->subject_id)->toBe($onlyAdmin->id)
        ->and($entry->properties->get('by'))->toBe('platform')
        ->and($entry->properties->get('verification_channel'))->toBe('phone_call')
        ->and($entry->properties->get('verification_note'))->toContain('Luis');
    Notification::assertSentTo($onlyAdmin, TwoFactorResetNotification::class);
});

it('CA-014-5: without the out-of-band verification platform is denied and nothing changes', function (array $verification): void {
    $organization = Memberships::organization();
    $onlyAdmin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    TwoFactor::enable($onlyAdmin);
    $platform = User::factory()->platformAdmin()->create();

    expect(fn () => app(ResetTwoFactorByPlatform::class)->handle($platform, $onlyAdmin, $verification))->toThrow(ValidationException::class);

    expect($onlyAdmin->fresh()->hasConfirmedTwoFactor())->toBeTrue();
    Notification::assertNothingSent();
    expect(Activity::query()->where('event', 'two_factor_reset')->count())->toBe(0);
})->with([
    'nothing' => [[]],
    'no note' => [['verification_channel' => 'phone_call']],
    'no channel' => [['verification_note' => 'Llamada al telefono registrado de la direccion.']],
    'the account email is not out of band' => [['verification_channel' => 'email', 'verification_note' => 'Confirmado por correo de la propia cuenta.']],
    'a note too short to identify anything' => [['verification_channel' => 'message', 'verification_note' => 'ok']],
]);

it('CA-014-5: platform cannot reset it while another administrator exists, nor its own account, and an organization member cannot use it', function (): void {
    [$organization, $admin, $member] = organizationWithTwoFactor();
    $platform = User::factory()->platformAdmin()->create();
    TwoFactor::enable($platform);

    expect(fn () => app(ResetTwoFactorByPlatform::class)->handle($platform, $member, verification()))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ResetTwoFactorByPlatform::class)->handle($platform, $platform, verification()))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ResetTwoFactorByPlatform::class)->handle($admin, $member, verification()))->toThrow(AuthorizationException::class);

    expect($admin->fresh()->hasConfirmedTwoFactor())->toBeTrue()
        ->and($platform->fresh()->hasConfirmedTwoFactor())->toBeTrue()
        ->and($member->fresh()->hasConfirmedTwoFactor())->toBeTrue();
    Notification::assertNothingSent();
});

it('CA-014-5: platform resets another platform user, who has no organization and so no other administrator', function (): void {
    $support = User::factory()->platformAdmin()->create();
    $colleague = User::factory()->platformAdmin()->create();
    TwoFactor::enable($colleague);

    app(ResetTwoFactorByPlatform::class)->handle($support, $colleague, verification());

    expect($colleague->fresh()->hasConfirmedTwoFactor())->toBeFalse();
});

it('CA-014-5: the platform panel shows the reset on the user page only when it applies and runs it with the verification', function (): void {
    $organization = Memberships::organization();
    $onlyAdmin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    TwoFactor::enable($onlyAdmin);
    [, , $coveredMember] = organizationWithTwoFactor();
    $platform = User::factory()->platformAdmin()->create();
    Filament::setCurrentPanel('platform');
    Auth::login($platform);

    Livewire::test(ViewUser::class, ['record' => $coveredMember->getKey()])->assertActionHidden('resetTwoFactor');

    Livewire::test(ViewUser::class, ['record' => $onlyAdmin->getKey()])
        ->assertActionVisible('resetTwoFactor')
        ->callAction('resetTwoFactor', [])
        ->assertHasActionErrors(['verification_channel', 'verification_note']);

    expect($onlyAdmin->fresh()->hasConfirmedTwoFactor())->toBeTrue();

    Livewire::test(ViewUser::class, ['record' => $onlyAdmin->getKey()])
        ->callAction('resetTwoFactor', verification())
        ->assertHasNoActionErrors();

    expect($onlyAdmin->fresh()->hasConfirmedTwoFactor())->toBeFalse();
});
