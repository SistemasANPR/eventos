<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Http\Requests\ChangeMemberRoleRequest;
use App\Http\Requests\InviteTeamMemberRequest;
use App\Models\User;
use App\Policies\MembershipPolicy;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Tenancy\Exceptions\MissingOrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
});

it('CA-003-26: the policy is registered for users', function (): void {
    expect(Gate::getPolicyFor(User::class))->toBeInstanceOf(MembershipPolicy::class);
});

it('CA-003-26: only an administrator may view, invite, change roles and deactivate', function (OrganizationRole $role, bool $allowed): void {
    $organization = Memberships::organization();
    $actor = Memberships::attach(User::factory()->create(), $organization, $role);
    $member = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);
    CurrentOrganization::set($organization);
    $gate = Gate::forUser($actor);

    expect($gate->allows('viewAny', User::class))->toBe($allowed)
        ->and($gate->allows('create', User::class))->toBe($allowed)
        ->and($gate->allows('changeRole', $member))->toBe($allowed)
        ->and($gate->allows('deactivate', $member))->toBe($allowed);
})->with([
    'org_admin' => [OrganizationRole::OrgAdmin, true],
    'organizer' => [OrganizationRole::Organizer, false],
    'reviewer' => [OrganizationRole::Reviewer, false],
]);

it('CA-003-26: denies by default without a role, with a deactivated membership or from another organization', function (): void {
    $organization = Memberships::organization();
    $withoutRole = Memberships::attach(User::factory()->create(), $organization);
    $deactivated = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin, 'deactivated');
    $adminOfOther = Memberships::attach(User::factory()->create(), Memberships::organization(), OrganizationRole::OrgAdmin);
    CurrentOrganization::set($organization);

    foreach ([$withoutRole, $deactivated, $adminOfOther] as $actor) {
        expect(Gate::forUser($actor)->allows('viewAny', User::class))->toBeFalse()
            ->and(Gate::forUser($actor)->allows('create', User::class))->toBeFalse();
    }
});

it('CA-003-24: members from another organization or already deactivated are not targets of role changes or deactivation', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $outsider = Memberships::attach(User::factory()->create(), Memberships::organization(), OrganizationRole::Reviewer);
    $deactivated = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer, 'deactivated');
    CurrentOrganization::set($organization);
    $gate = Gate::forUser($admin);

    expect($gate->allows('changeRole', $outsider))->toBeFalse()
        ->and($gate->allows('deactivate', $outsider))->toBeFalse()
        ->and($gate->allows('changeRole', $deactivated))->toBeFalse()
        ->and($gate->allows('deactivate', $deactivated))->toBeFalse();
});

it('CA-003-26: without an organization context the policy fails instead of answering', function (): void {
    $actor = User::factory()->create();

    expect(fn () => Gate::forUser($actor)->allows('viewAny', User::class))
        ->toThrow(MissingOrganizationContext::class);
});

it('CA-003-23: the invitation request validates without an HTTP request and normalizes nothing it does not own', function (): void {
    $valid = ['name' => 'Ana', 'email' => 'ana@example.test', 'role' => 'organizer'];

    expect(Validator::make($valid, InviteTeamMemberRequest::fieldRules())->passes())->toBeTrue()
        ->and((new InviteTeamMemberRequest)->rules())->toEqual(InviteTeamMemberRequest::fieldRules());

    foreach ([
        ['name' => ''],
        ['name' => str_repeat('a', 256)],
        ['email' => 'no-email'],
        ['email' => str_repeat('a', 250).'@x.test'],
        ['role' => 'platform'],
        ['role' => ''],
        ['is_platform_admin' => true],
    ] as $change) {
        expect(Validator::make([...$valid, ...$change], InviteTeamMemberRequest::fieldRules())->fails())->toBeTrue();
    }
});

it('CA-003-30: the role-change request accepts only the three organization roles', function (): void {
    $rules = ChangeMemberRoleRequest::fieldRules();

    foreach (['org_admin', 'organizer', 'reviewer'] as $role) {
        expect(Validator::make(['role' => $role], $rules)->passes())->toBeTrue();
    }
    foreach (['platform', 'superuser', '', null] as $role) {
        expect(Validator::make(['role' => $role], $rules)->fails())->toBeTrue();
    }

    expect(array_keys((new ChangeMemberRoleRequest)->rules()))->toBe(array_keys($rules));
});
