<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Filament\Resources\TeamMembers\Pages\ListTeamMembers;
use App\Models\KnownDevice;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\SetPasswordNotification;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();
    Filament::setCurrentPanel('admin');
});

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/** Entra al panel como el usuario, con la organización como tenant y como contexto (lo que hace el middleware del panel). */
function actAsResender(User $user, Organization $organization): void
{
    Auth::shouldUse('web');
    Auth::guard('web')->setUser($user);
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);
}

it('CA-003-21: the resend action queues the set-password email and notifies success', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $member = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer);
    RateLimiter::clear('team-invite:'.$admin->id);
    actAsResender($admin, $organization);

    Livewire::test(ListTeamMembers::class)
        ->callTableAction('resendInvitation', $member)
        ->assertNotified(__('team.resend.done'));

    Notification::assertSentToTimes($member, SetPasswordNotification::class, 1);
    expect(Activity::query()->where('event', 'member_invitation_resent')->count())->toBe(1);
});

it('CA-003-26: the resend action is hidden for a deactivated member and visible for an active one', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $deactivated = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer, 'deactivated');
    $active = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);
    actAsResender($admin, $organization);

    Livewire::test(ListTeamMembers::class)
        ->assertTableActionHidden('resendInvitation', $deactivated)
        ->assertTableActionVisible('resendInvitation', $active);
});

it('Reenvio: the resend action reports a member who already signed in and sends nothing', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $member = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer);
    KnownDevice::query()->create(['user_id' => $member->id, 'fingerprint_hash' => str_repeat('b', 64), 'last_seen_at' => now()]);
    RateLimiter::clear('team-invite:'.$admin->id);
    actAsResender($admin, $organization);

    Livewire::test(ListTeamMembers::class)
        ->callTableAction('resendInvitation', $member)
        ->assertNotified(__('team.errors.not_pending'));

    Notification::assertNothingSent();
});

it('CA-003-29: the resend action reports the rate limit', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $member = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer);
    RateLimiter::clear('team-invite:'.$admin->id);
    foreach (range(1, 10) as $ignored) {
        RateLimiter::hit('team-invite:'.$admin->id, 60);
    }
    actAsResender($admin, $organization);

    Livewire::test(ListTeamMembers::class)
        ->callTableAction('resendInvitation', $member)
        ->assertNotified(__('team.invite.throttled'));

    Notification::assertNothingSent();
});
