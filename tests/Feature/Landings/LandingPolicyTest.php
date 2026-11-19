<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Event;
use App\Models\User;
use App\Support\Auth\RolePermissions;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
});

/** @return bool resultado de la Policy dentro de la organización del evento */
function landingPolicyAllows(User $user, string $ability, Event $event): bool
{
    return CurrentOrganization::run(
        $event->organization,
        fn (): bool => Gate::forUser($user->unsetRelation('roles')->unsetRelation('permissions'))->allows($ability, $event),
    );
}

/** @return array{0: User, 1: Event} */
function landingPolicyScenario(?OrganizationRole $role): array
{
    $organization = Memberships::organization();
    $user = Memberships::attach(User::factory()->create(), $organization, $role);

    return [$user, Event::factory()->create(['organization_id' => $organization->id])];
}

it('CA-022-6: org_admin and organizer view the draft, save, update and publish the landing', function (OrganizationRole $role): void {
    [$user, $event] = landingPolicyScenario($role);

    expect(landingPolicyAllows($user, 'viewLanding', $event))->toBeTrue()
        ->and(landingPolicyAllows($user, 'updateLanding', $event))->toBeTrue()
        ->and(landingPolicyAllows($user, 'publishLanding', $event))->toBeTrue();
})->with([
    'org_admin' => [OrganizationRole::OrgAdmin],
    'organizer' => [OrganizationRole::Organizer],
]);

it('CA-022-7: the reviewer and a member without role cannot touch the landing', function (?OrganizationRole $role): void {
    [$user, $event] = landingPolicyScenario($role);

    expect(landingPolicyAllows($user, 'viewLanding', $event))->toBeFalse()
        ->and(landingPolicyAllows($user, 'updateLanding', $event))->toBeFalse()
        ->and(landingPolicyAllows($user, 'publishLanding', $event))->toBeFalse();
})->with([
    'reviewer' => [OrganizationRole::Reviewer],
    'no role' => [null],
]);

it('CA-022-4: an admin of another organization is denied on the landing of an event that is not theirs', function (): void {
    [, $event] = landingPolicyScenario(OrganizationRole::OrgAdmin);
    $outsider = Memberships::attach(User::factory()->create(), Memberships::organization(), OrganizationRole::OrgAdmin);

    expect(landingPolicyAllows($outsider, 'viewLanding', $event))->toBeFalse()
        ->and(landingPolicyAllows($outsider, 'updateLanding', $event))->toBeFalse()
        ->and(landingPolicyAllows($outsider, 'publishLanding', $event))->toBeFalse();
});

it('CA-022-6: the landing permission is the existing events.manage, with no new permission', function (): void {
    expect(RolePermissions::permissions())->not->toContain('landings.manage')
        ->and(array_values(array_filter(RolePermissions::permissions(), fn (string $p): bool => str_contains($p, 'landing'))))->toBe([]);
});
