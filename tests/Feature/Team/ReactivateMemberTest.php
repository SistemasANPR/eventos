<?php

declare(strict_types=1);

use App\Actions\Organizations\InviteTeamMember;
use App\Actions\Organizations\ReactivateMember;
use App\Enums\OrganizationRole;
use App\Exceptions\Domain\MemberNotActive;
use App\Exceptions\Domain\MemberNotDeactivated;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Mail::fake();
    Notification::fake();
});

afterEach(function (): void {
    CurrentOrganization::forget();
});

/** @return list<string> roles del usuario dentro de la organización */
function reactivationRolesOf(Organization $organization, User $user): array
{
    return CurrentOrganization::run($organization, fn (): array => $user->fresh()->getRoleNames()->sort()->values()->all());
}

/** @return object{status: string, deactivated_at: ?string}|null */
function reactivationMembership(Organization $organization, User $user): ?object
{
    return DB::table('organization_user')->where('organization_id', $organization->id)->where('user_id', $user->id)->first();
}

/** @return array{0: Organization, 1: User, 2: User} organización, su administrador y un integrante desactivado (organizer) */
function organizationWithDeactivatedMember(): array
{
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $member = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer, 'deactivated');

    return [$organization, $admin, $member];
}

it('CA-003-32: reactivates the member with the confirmed role', function (string $role): void {
    [$organization, $admin, $member] = organizationWithDeactivatedMember();

    app(ReactivateMember::class)->handle($admin, $organization, $member, $role);

    $membership = reactivationMembership($organization, $member);
    expect($membership?->status)->toBe('active')
        ->and($membership->deactivated_at)->toBeNull()
        ->and($member->fresh()->hasActiveMembershipIn($organization))->toBeTrue()
        ->and(reactivationRolesOf($organization, $member))->toBe([$role]);
})->with(['org_admin', 'organizer', 'reviewer']);

it('CA-003-32: the member recovers the panel on the next request', function (): void {
    [$organization, $admin, $member] = organizationWithDeactivatedMember();
    expect($this->actingAs($member)->get("/admin/{$organization->slug}")->status())->toBeIn([403, 404]);

    app(ReactivateMember::class)->handle($admin, $organization, $member, 'organizer');

    $this->actingAs($member->fresh())->get("/admin/{$organization->slug}")->assertOk();
});

it('CA-003-33: rejects a role outside the list, platform included, and changes nothing', function (string $role): void {
    [$organization, $admin, $member] = organizationWithDeactivatedMember();

    expect(fn () => app(ReactivateMember::class)->handle($admin, $organization, $member, $role))
        ->toThrow(ValidationException::class);

    expect(reactivationMembership($organization, $member)?->status)->toBe('deactivated')
        ->and(reactivationRolesOf($organization, $member))->toBe(['organizer'])
        ->and(Activity::query()->count())->toBe(0);
})->with(['platform', 'superuser', '']);

it('CA-003-34: refuses to reactivate an active member and changes nothing', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $member = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);

    expect(fn () => app(ReactivateMember::class)->handle($admin, $organization, $member, 'org_admin'))
        ->toThrow(MemberNotDeactivated::class);

    expect(reactivationRolesOf($organization, $member))->toBe(['reviewer'])
        ->and(Activity::query()->count())->toBe(0);
});

it('CA-003-34: refuses a user who is not a member of the organization and changes nothing', function (): void {
    $organizationA = Memberships::organization();
    $organizationB = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organizationA, OrganizationRole::OrgAdmin);
    $outsider = Memberships::attach(User::factory()->create(), $organizationB, OrganizationRole::Organizer, 'deactivated');

    expect(fn () => app(ReactivateMember::class)->handle($admin, $organizationA, $outsider, 'organizer'))
        ->toThrow(MemberNotDeactivated::class);

    expect(reactivationMembership($organizationB, $outsider)?->status)->toBe('deactivated')
        ->and($outsider->fresh()->organizations()->whereKey($organizationA->id)->exists())->toBeFalse();
});

it('CA-003-34: the domain exception has a stable code and shares the type of MemberNotActive', function (): void {
    expect(MemberNotDeactivated::CODE)->toBe('MEMBER_NOT_DEACTIVATED')
        ->and(new MemberNotDeactivated)->toBeInstanceOf(InvalidArgumentException::class)
        ->and((new MemberNotDeactivated)->getMessage())->toContain('MEMBER_NOT_DEACTIVATED')
        ->and(get_parent_class(MemberNotActive::class))->toBe(get_parent_class(MemberNotDeactivated::class));
});

it('CA-003-35: refuses an organizer and a reviewer and changes nothing', function (OrganizationRole $role): void {
    [$organization, , $member] = organizationWithDeactivatedMember();
    $actor = Memberships::attach(User::factory()->create(), $organization, $role);

    expect(fn () => app(ReactivateMember::class)->handle($actor, $organization, $member, 'organizer'))
        ->toThrow(AuthorizationException::class);

    expect(reactivationMembership($organization, $member)?->status)->toBe('deactivated')
        ->and(Activity::query()->count())->toBe(0);
})->with([OrganizationRole::Organizer, OrganizationRole::Reviewer]);

it('CA-003-35: an administrator of another organization cannot reactivate here', function (): void {
    [$organization, , $member] = organizationWithDeactivatedMember();
    $adminOfB = Memberships::attach(User::factory()->create(), Memberships::organization(), OrganizationRole::OrgAdmin);

    expect(fn () => app(ReactivateMember::class)->handle($adminOfB, $organization, $member, 'organizer'))
        ->toThrow(AuthorizationException::class);

    expect(reactivationMembership($organization, $member)?->status)->toBe('deactivated');
});

it('CA-003-36: does not touch the membership or the roles of the user in another organization', function (): void {
    [$organizationA, $adminA, $member] = organizationWithDeactivatedMember();
    $organizationB = Memberships::organization();
    Memberships::attach($member, $organizationB, OrganizationRole::Reviewer, 'deactivated');
    $beforeB = reactivationMembership($organizationB, $member);

    app(ReactivateMember::class)->handle($adminA, $organizationA, $member, 'org_admin');

    $afterB = reactivationMembership($organizationB, $member);
    expect(reactivationRolesOf($organizationA, $member))->toBe(['org_admin'])
        ->and(reactivationRolesOf($organizationB, $member))->toBe(['reviewer'])
        ->and($afterB?->status)->toBe('deactivated')
        ->and($afterB->deactivated_at)->toBe($beforeB?->deactivated_at)
        ->and($member->fresh()->hasActiveMembershipIn($organizationB))->toBeFalse();
});

it('CA-003-37: audits who reactivated whom with the previous and the new role, without secrets', function (): void {
    [$organization, $admin, $member] = organizationWithDeactivatedMember();

    app(ReactivateMember::class)->handle($admin, $organization, $member, 'reviewer');

    $entry = Activity::query()->where('event', 'member_reactivated')->sole();
    expect($entry->causer_id)->toBe($admin->id)
        ->and($entry->subject_id)->toBe($member->id)
        ->and($entry->properties->all())->toBe(['organization_id' => $organization->id, 'from' => ['organizer'], 'to' => 'reviewer'])
        ->and(json_encode($entry->toArray()))->not->toContain('password');
    expect(json_encode($entry->toArray()))->not->toContain('token');
});

it('CA-003-38: the invitation of a deactivated member is rejected pointing to Reactivate and sends nothing', function (): void {
    [$organization, $admin] = organizationWithDeactivatedMember();
    Memberships::attach(User::factory()->create(['email' => 'fuera@example.test']), $organization, OrganizationRole::Reviewer, 'deactivated');

    try {
        app(InviteTeamMember::class)->handle($admin, $organization, ['name' => 'Fuera', 'email' => 'Fuera@example.test', 'role' => 'organizer']);
        $this->fail('Debió rechazar el alta.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['email'])->toBe([__('team.invite.deactivated_member')]);
    }

    Mail::assertNothingSent();
    Notification::assertNothingSent();
    expect(__('team.invite.deactivated_member'))->toContain('Reactivar')
        ->and(__('team.invite.deactivated_member'))->not->toBe(__('team.invite.already_member'));
});

it('CA-003-38: the invitation of an active member keeps its message', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    Memberships::attach(User::factory()->create(['email' => 'dentro@example.test']), $organization, OrganizationRole::Reviewer);

    try {
        app(InviteTeamMember::class)->handle($admin, $organization, ['name' => 'Dentro', 'email' => 'dentro@example.test', 'role' => 'organizer']);
        $this->fail('Debió rechazar el alta.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['email'])->toBe([__('team.invite.already_member')]);
    }
});
