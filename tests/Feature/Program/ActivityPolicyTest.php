<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Event;
use App\Models\EventActivity;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
});

/** @return bool resultado de la Policy dentro de la organización del evento */
function activityPolicyAllows(User $user, string $ability, mixed $arguments, Event $event): bool
{
    return CurrentOrganization::run(
        $event->organization,
        fn (): bool => Gate::forUser($user->unsetRelation('roles')->unsetRelation('permissions'))->allows($ability, $arguments),
    );
}

/** @return array{0: User, 1: Event, 2: EventActivity} */
function activityPolicyScenario(?OrganizationRole $role): array
{
    $organization = Memberships::organization();
    $user = Memberships::attach(User::factory()->create(), $organization, $role);
    $event = Event::factory()->create(['organization_id' => $organization->id]);
    $activity = CurrentOrganization::run($organization, fn () => EventActivity::factory()->create(['event_id' => $event->id]));

    return [$user, $event, $activity];
}

it('CA-021-15: org_admin and organizer view, create and edit activities; only org_admin deletes', function (): void {
    foreach ([[OrganizationRole::OrgAdmin, true], [OrganizationRole::Organizer, false]] as [$role, $deletes]) {
        [$user, $event, $activity] = activityPolicyScenario($role);

        expect(activityPolicyAllows($user, 'viewAny', EventActivity::class, $event))->toBeTrue()
            ->and(activityPolicyAllows($user, 'view', $activity, $event))->toBeTrue()
            ->and(activityPolicyAllows($user, 'create', [EventActivity::class, $event], $event))->toBeTrue()
            ->and(activityPolicyAllows($user, 'update', $activity, $event))->toBeTrue()
            ->and(activityPolicyAllows($user, 'delete', $activity, $event))->toBe($deletes);
    }
});

it('CA-021-15: the reviewer and a member without role can do nothing with activities', function (?OrganizationRole $role): void {
    [$user, $event, $activity] = activityPolicyScenario($role);

    expect(activityPolicyAllows($user, 'viewAny', EventActivity::class, $event))->toBeFalse()
        ->and(activityPolicyAllows($user, 'view', $activity, $event))->toBeFalse()
        ->and(activityPolicyAllows($user, 'create', [EventActivity::class, $event], $event))->toBeFalse()
        ->and(activityPolicyAllows($user, 'update', $activity, $event))->toBeFalse()
        ->and(activityPolicyAllows($user, 'delete', $activity, $event))->toBeFalse();
})->with([
    'reviewer' => [OrganizationRole::Reviewer],
    'no role' => [null],
]);

it('CA-021-15: a user of another organization is denied on activities of an event that is not theirs', function (): void {
    [, $event, $activity] = activityPolicyScenario(OrganizationRole::OrgAdmin);
    $outsider = Memberships::attach(User::factory()->create(), Memberships::organization(), OrganizationRole::OrgAdmin);

    expect(activityPolicyAllows($outsider, 'view', $activity, $event))->toBeFalse()
        ->and(activityPolicyAllows($outsider, 'create', [EventActivity::class, $event], $event))->toBeFalse()
        ->and(activityPolicyAllows($outsider, 'update', $activity, $event))->toBeFalse()
        ->and(activityPolicyAllows($outsider, 'delete', $activity, $event))->toBeFalse();
});
