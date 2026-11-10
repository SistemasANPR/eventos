<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Filament\Resources\TeamMembers\Pages\ListTeamMembers;
use App\Filament\Resources\TeamMembers\TeamMemberResource;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\MembershipAddedNotification;
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
function actInTeamPanel(User $user, Organization $organization): void
{
    Auth::shouldUse('web');
    Auth::guard('web')->setUser($user);
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);
}

/** @return array{0: Organization, 1: User} */
function teamPanelOrganizationWithAdmin(): array
{
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);

    return [$organization, $admin];
}

it('CA-003-24: the list shows only the members of the organization with name, email, role and status', function (): void {
    [$organization, $admin] = teamPanelOrganizationWithAdmin();
    $organizer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer);
    $deactivated = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer, 'deactivated');
    $outsider = Memberships::attach(User::factory()->create(), Memberships::organization(), OrganizationRole::OrgAdmin);
    actInTeamPanel($admin, $organization);

    Livewire::test(ListTeamMembers::class)
        ->assertCanSeeTableRecords([$admin, $organizer, $deactivated])
        ->assertCanNotSeeTableRecords([$outsider])
        ->assertTableColumnStateSet('email', $organizer->email, $organizer)
        ->assertTableColumnStateSet('role', __('team.roles.organizer'), $organizer)
        ->assertTableColumnStateSet('status', __('team.status.active'), $organizer)
        ->assertTableColumnStateSet('status', __('team.status.deactivated'), $deactivated);
});

it('CA-003-24: the panel shows the member in the real route for an administrator and refuses an organizer', function (): void {
    [$organization, $admin] = teamPanelOrganizationWithAdmin();
    $organizer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer);

    $this->actingAs($admin)->get(TeamMemberResource::getUrl('index', tenant: $organization))->assertOk();

    expect($this->actingAs($organizer)->get(TeamMemberResource::getUrl('index', tenant: $organization))->status())->toBeIn([403, 404]);
});

it('CA-003-26: an organizer and a reviewer cannot open the team page', function (OrganizationRole $role): void {
    $organization = Memberships::organization();
    $user = Memberships::attach(User::factory()->create(), $organization, $role);
    actInTeamPanel($user, $organization);

    Livewire::test(ListTeamMembers::class)->assertForbidden();
})->with([OrganizationRole::Organizer, OrganizationRole::Reviewer]);

it('CA-003-26: the team navigation entry is hidden for who cannot manage the team', function (): void {
    [$organization, $admin] = teamPanelOrganizationWithAdmin();
    $organizer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer);

    actInTeamPanel($admin, $organization);
    expect(TeamMemberResource::canViewAny())->toBeTrue();

    actInTeamPanel($organizer, $organization);
    expect(TeamMemberResource::canViewAny())->toBeFalse();
});

it('CA-003-21: the invite action creates the member and queues the set-password link', function (): void {
    [$organization, $admin] = teamPanelOrganizationWithAdmin();
    actInTeamPanel($admin, $organization);
    RateLimiter::clear('team-invite:'.$admin->id);

    Livewire::test(ListTeamMembers::class)
        ->callAction('invite', ['name' => 'Nueva Persona', 'email' => 'Nueva@Example.test', 'role' => 'organizer'])
        ->assertHasNoActionErrors()
        ->assertNotified(__('team.invite.sent'));

    $member = User::query()->where('email', 'nueva@example.test')->sole();
    expect($member->hasActiveMembershipIn($organization))->toBeTrue();
    Notification::assertSentTo($member, SetPasswordNotification::class);
});

it('CA-003-22: the invite action gives the same answer for an email that exists in another organization', function (): void {
    [$organization, $admin] = teamPanelOrganizationWithAdmin();
    $existing = Memberships::attach(User::factory()->create(['email' => 'viva@example.test']), Memberships::organization(), OrganizationRole::Reviewer);
    actInTeamPanel($admin, $organization);
    RateLimiter::clear('team-invite:'.$admin->id);

    Livewire::test(ListTeamMembers::class)
        ->callAction('invite', ['name' => 'Cualquiera', 'email' => 'viva@example.test', 'role' => 'reviewer'])
        ->assertHasNoActionErrors()
        ->assertNotified(__('team.invite.sent'));

    Notification::assertSentTo($existing, MembershipAddedNotification::class);
});

it('CA-003-23: validation errors of the Action reach the fields of the form', function (): void {
    [$organization, $admin] = teamPanelOrganizationWithAdmin();
    $inside = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);
    actInTeamPanel($admin, $organization);
    RateLimiter::clear('team-invite:'.$admin->id);

    Livewire::test(ListTeamMembers::class)
        ->callAction('invite', ['name' => 'Ana', 'email' => $inside->email, 'role' => 'organizer'])
        ->assertHasActionErrors(['email']);

    Livewire::test(ListTeamMembers::class)
        ->callAction('invite', ['name' => 'Ana', 'email' => 'no-es-correo', 'role' => 'organizer'])
        ->assertHasActionErrors(['email']);

    Notification::assertNothingSent();
});

it('CA-003-29: the invite action reports the limit instead of failing', function (): void {
    [$organization, $admin] = teamPanelOrganizationWithAdmin();
    actInTeamPanel($admin, $organization);
    RateLimiter::clear('team-invite:'.$admin->id);

    $page = Livewire::test(ListTeamMembers::class);
    foreach (range(1, 10) as $i) {
        $page->callAction('invite', ['name' => "P{$i}", 'email' => "p{$i}@example.test", 'role' => 'reviewer']);
    }
    $page->callAction('invite', ['name' => 'Once', 'email' => 'p11@example.test', 'role' => 'reviewer'])
        ->assertNotified(__('team.invite.throttled'));

    expect(User::query()->where('email', 'p11@example.test')->exists())->toBeFalse();
});

it('CA-003-24: the change-role action changes the role of a member of the organization and audits it', function (): void {
    [$organization, $admin] = teamPanelOrganizationWithAdmin();
    $member = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);
    actInTeamPanel($admin, $organization);

    Livewire::test(ListTeamMembers::class)
        ->callTableAction('changeRole', $member, ['role' => 'organizer'])
        ->assertHasNoActionErrors();

    expect(CurrentOrganization::run($organization, fn () => $member->fresh()->getRoleNames()->all()))->toBe(['organizer'])
        ->and(Activity::query()->where('event', 'role_changed')->count())->toBe(1);
});

it('CA-003-30: the change-role action rejects the platform role', function (): void {
    [$organization, $admin] = teamPanelOrganizationWithAdmin();
    $member = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);
    actInTeamPanel($admin, $organization);

    Livewire::test(ListTeamMembers::class)
        ->callTableAction('changeRole', $member, ['role' => 'platform'])
        ->assertHasActionErrors(['role']);

    expect(CurrentOrganization::run($organization, fn () => $member->fresh()->getRoleNames()->all()))->toBe(['reviewer']);
});

it('CA-003-25: the change-role action reports the last administrator instead of failing', function (): void {
    [$organization, $admin] = teamPanelOrganizationWithAdmin();
    actInTeamPanel($admin, $organization);

    Livewire::test(ListTeamMembers::class)
        ->callTableAction('changeRole', $admin, ['role' => 'organizer'])
        ->assertNotified(__('team.errors.last_admin'));

    expect(CurrentOrganization::run($organization, fn () => $admin->fresh()->getRoleNames()->all()))->toBe(['org_admin']);
});

it('CA-003-24: the deactivate action deactivates a member and audits it', function (): void {
    [$organization, $admin] = teamPanelOrganizationWithAdmin();
    $member = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer);
    actInTeamPanel($admin, $organization);

    Livewire::test(ListTeamMembers::class)
        ->callTableAction('deactivate', $member)
        ->assertNotified(__('team.deactivate.done'));

    expect($member->fresh()->hasActiveMembershipIn($organization))->toBeFalse()
        ->and(Activity::query()->where('event', 'member_deactivated')->count())->toBe(1);
});

it('CA-003-25: the deactivate action reports the last administrator and keeps the access', function (): void {
    [$organization, $admin] = teamPanelOrganizationWithAdmin();
    actInTeamPanel($admin, $organization);

    Livewire::test(ListTeamMembers::class)
        ->callTableAction('deactivate', $admin)
        ->assertNotified(__('team.errors.last_admin'));

    expect($admin->fresh()->hasActiveMembershipIn($organization))->toBeTrue();
});

it('CA-003-24: a user of another organization cannot be reached from the table of this one', function (): void {
    [$organization, $admin] = teamPanelOrganizationWithAdmin();
    $organizationB = Memberships::organization();
    $outsider = Memberships::attach(User::factory()->create(), $organizationB, OrganizationRole::Organizer);
    actInTeamPanel($admin, $organization);

    expect(fn () => Livewire::test(ListTeamMembers::class)->callTableAction('deactivate', $outsider))
        ->toThrow(Exception::class, 'no longer exists');

    expect($outsider->fresh()->hasActiveMembershipIn($organizationB))->toBeTrue();
});

it('CA-003-26: the actions are hidden for a deactivated member and for the member in the row it cannot touch', function (): void {
    [$organization, $admin] = teamPanelOrganizationWithAdmin();
    $deactivated = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer, 'deactivated');
    actInTeamPanel($admin, $organization);

    Livewire::test(ListTeamMembers::class)
        ->assertTableActionHidden('deactivate', $deactivated)
        ->assertTableActionHidden('changeRole', $deactivated);
});
