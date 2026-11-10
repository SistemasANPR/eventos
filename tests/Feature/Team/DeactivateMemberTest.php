<?php

declare(strict_types=1);

use App\Actions\Organizations\ChangeMemberRole;
use App\Actions\Organizations\DeactivateMember;
use App\Enums\OrganizationRole;
use App\Exceptions\Domain\LastOrganizationAdmin;
use App\Exceptions\Domain\MemberNotActive;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
});

/** @return list<string> */
function teamRolesOf(Organization $organization, User $user): array
{
    return CurrentOrganization::run($organization, fn (): array => $user->fresh()->getRoleNames()->sort()->values()->all());
}

it('CA-003-27: deactivates the membership keeping the row, the roles and the status fields', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $member = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer);

    app(DeactivateMember::class)->handle($admin, $organization, $member);

    $membership = DB::table('organization_user')->where('organization_id', $organization->id)->where('user_id', $member->id)->first();
    expect($membership?->status)->toBe('deactivated')
        ->and($membership->deactivated_at)->not->toBeNull()
        ->and($member->fresh()->hasActiveMembershipIn($organization))->toBeFalse()
        ->and(User::query()->whereKey($member->id)->exists())->toBeTrue()
        ->and(teamRolesOf($organization, $member))->toBe(['organizer']);
});

it('CA-003-27: a deactivated member loses the panel on an open session', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $member = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer);
    $this->actingAs($member)->get("/admin/{$organization->slug}")->assertOk();

    app(DeactivateMember::class)->handle($admin, $organization, $member);

    expect($this->actingAs($member->fresh())->get("/admin/{$organization->slug}")->status())->toBeIn([403, 404]);
});

it('CA-003-27: only the membership of that organization is deactivated', function (): void {
    $organizationA = Memberships::organization();
    $organizationB = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organizationA, OrganizationRole::OrgAdmin);
    $member = Memberships::attach(User::factory()->create(), $organizationA, OrganizationRole::Organizer);
    Memberships::attach($member, $organizationB, OrganizationRole::Organizer);

    app(DeactivateMember::class)->handle($admin, $organizationA, $member);

    expect($member->fresh()->hasActiveMembershipIn($organizationB))->toBeTrue();
});

it('CA-003-24: refuses to deactivate a user who is not a member of the organization', function (): void {
    $organizationA = Memberships::organization();
    $organizationB = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organizationA, OrganizationRole::OrgAdmin);
    $outsider = Memberships::attach(User::factory()->create(), $organizationB, OrganizationRole::Organizer);

    expect(fn () => app(DeactivateMember::class)->handle($admin, $organizationA, $outsider))
        ->toThrow(MemberNotActive::class);

    expect($outsider->fresh()->hasActiveMembershipIn($organizationB))->toBeTrue();
});

it('CA-003-24: refuses to deactivate someone who is already deactivated', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $member = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer, 'deactivated');

    expect(fn () => app(DeactivateMember::class)->handle($admin, $organization, $member))
        ->toThrow(MemberNotActive::class);
});

it('CA-003-25: refuses to deactivate the last administrator, even if it is oneself', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer);

    expect(fn () => app(DeactivateMember::class)->handle($admin, $organization, $admin))
        ->toThrow(LastOrganizationAdmin::class);

    expect($admin->fresh()->hasActiveMembershipIn($organization))->toBeTrue()
        ->and(Activity::query()->count())->toBe(0);
});

it('CA-003-25: a deactivated administrator does not count as an active one', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin, 'deactivated');

    expect(fn () => app(DeactivateMember::class)->handle($admin, $organization, $admin))
        ->toThrow(LastOrganizationAdmin::class);
});

it('CA-003-25: an administrator of another organization does not count as one of this organization', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    Memberships::attach(User::factory()->create(), Memberships::organization(), OrganizationRole::OrgAdmin);

    expect(fn () => app(DeactivateMember::class)->handle($admin, $organization, $admin))
        ->toThrow(LastOrganizationAdmin::class);
});

it('CA-003-25: allows deactivating an administrator when another active one remains', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $other = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);

    app(DeactivateMember::class)->handle($admin, $organization, $other);

    expect($other->fresh()->hasActiveMembershipIn($organization))->toBeFalse();
});

it('CA-003-25: refuses to remove the administrator role from the last administrator', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);

    expect(fn () => app(ChangeMemberRole::class)->handle($admin, $organization, $admin, OrganizationRole::Organizer))
        ->toThrow(LastOrganizationAdmin::class);

    expect(teamRolesOf($organization, $admin))->toBe(['org_admin'])
        ->and(Activity::query()->count())->toBe(0);
});

it('CA-003-25: allows removing the administrator role when another active one remains', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);

    app(ChangeMemberRole::class)->handle($admin, $organization, $admin, OrganizationRole::Organizer);

    expect(teamRolesOf($organization, $admin))->toBe(['organizer']);
});

it('CA-003-26: refuses to deactivate to an organizer or a reviewer and changes nothing', function (OrganizationRole $role): void {
    $organization = Memberships::organization();
    $actor = Memberships::attach(User::factory()->create(), $organization, $role);
    $member = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);

    expect(fn () => app(DeactivateMember::class)->handle($actor, $organization, $member))
        ->toThrow(AuthorizationException::class);

    expect($member->fresh()->hasActiveMembershipIn($organization))->toBeTrue()
        ->and(Activity::query()->count())->toBe(0);
})->with([OrganizationRole::Organizer, OrganizationRole::Reviewer]);

it('CA-003-26: an administrator of another organization cannot deactivate here', function (): void {
    $organizationA = Memberships::organization();
    $organizationB = Memberships::organization();
    $adminOfB = Memberships::attach(User::factory()->create(), $organizationB, OrganizationRole::OrgAdmin);
    $member = Memberships::attach(User::factory()->create(), $organizationA, OrganizationRole::Reviewer);

    expect(fn () => app(DeactivateMember::class)->handle($adminOfB, $organizationA, $member))
        ->toThrow(AuthorizationException::class);

    expect($member->fresh()->hasActiveMembershipIn($organizationA))->toBeTrue();
});

it('CA-003-28: records who deactivated whom, without secrets', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $member = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer);

    app(DeactivateMember::class)->handle($admin, $organization, $member);

    $entry = Activity::query()->where('event', 'member_deactivated')->sole();
    expect($entry->causer_id)->toBe($admin->id)
        ->and($entry->subject_id)->toBe($member->id)
        ->and($entry->properties->all())->toBe(['organization_id' => $organization->id])
        ->and(json_encode($entry->toArray()))->not->toContain('password');
    expect(json_encode($entry->toArray()))->not->toContain('token');
});
