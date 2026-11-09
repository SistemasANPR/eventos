<?php

declare(strict_types=1);

use App\Actions\Organizations\ChangeMemberRole;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
});

/** @return list<string> nombres de rol del usuario dentro de la organización */
function rolesIn(Organization $organization, User $user): array
{
    return CurrentOrganization::run($organization, fn (): array => $user->fresh()->getRoleNames()->sort()->values()->all());
}

it('CA-001-23: changes only the roles of that organization', function (): void {
    $organizationA = Memberships::organization();
    $organizationB = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organizationA, OrganizationRole::OrgAdmin);
    $member = Memberships::attach(User::factory()->create(), $organizationA, OrganizationRole::Organizer);
    Memberships::attach($member, $organizationB, OrganizationRole::Reviewer);

    app(ChangeMemberRole::class)->handle($admin, $organizationA, $member, OrganizationRole::OrgAdmin);

    expect(rolesIn($organizationA, $member))->toBe(['org_admin'])
        ->and(rolesIn($organizationB, $member))->toBe(['reviewer']);
});

it('CA-001-23: the membership table keeps no role', function (): void {
    expect(Schema::hasColumn('organization_user', 'role'))->toBeFalse();
});

it('CA-001-23: records who changed whose role, from which role to which', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $member = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer);

    app(ChangeMemberRole::class)->handle($admin, $organization, $member, OrganizationRole::Reviewer);

    $entry = Activity::query()->where('event', 'role_changed')->sole();

    expect($entry->causer_id)->toBe($admin->id)
        ->and($entry->subject_id)->toBe($member->id)
        ->and($entry->properties->all())->toBe([
            'organization_id' => $organization->id,
            'from' => ['organizer'],
            'to' => 'reviewer',
        ]);
});

it('CA-001-23: refuses the change to someone without team.manage', function (): void {
    $organization = Memberships::organization();
    $organizer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer);
    $member = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);

    expect(fn () => app(ChangeMemberRole::class)->handle($organizer, $organization, $member, OrganizationRole::OrgAdmin))
        ->toThrow(AuthorizationException::class);

    expect(rolesIn($organization, $member))->toBe(['reviewer'])
        ->and(Activity::query()->count())->toBe(0);
});

it('CA-001-23: refuses to change the role of someone who is not an active member', function (): void {
    $organizationA = Memberships::organization();
    $organizationB = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organizationA, OrganizationRole::OrgAdmin);
    $outsider = Memberships::attach(User::factory()->create(), $organizationB, OrganizationRole::Organizer);

    expect(fn () => app(ChangeMemberRole::class)->handle($admin, $organizationA, $outsider, OrganizationRole::OrgAdmin))
        ->toThrow(InvalidArgumentException::class);

    expect(rolesIn($organizationA, $outsider))->toBe([])
        ->and(Role::query()->where('organization_id', $organizationA->id)->count())->toBe(3);
});

it('CA-001-23: an admin of one organization cannot change roles in another', function (): void {
    $organizationA = Memberships::organization();
    $organizationB = Memberships::organization();
    $adminOfA = Memberships::attach(User::factory()->create(), $organizationA, OrganizationRole::OrgAdmin);
    $memberOfB = Memberships::attach(User::factory()->create(), $organizationB, OrganizationRole::Organizer);

    expect(fn () => app(ChangeMemberRole::class)->handle($adminOfA, $organizationB, $memberOfB, OrganizationRole::OrgAdmin))
        ->toThrow(AuthorizationException::class);

    expect(rolesIn($organizationB, $memberOfB))->toBe(['organizer']);
});
