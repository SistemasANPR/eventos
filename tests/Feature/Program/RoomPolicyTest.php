<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Event;
use App\Models\Room;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
});

/** @return array{0: User, 1: Event, 2: Room} usuario con el rol, evento de su organización y una sala */
function roomPolicyScenario(?OrganizationRole $role): array
{
    $organization = Memberships::organization();
    $user = Memberships::attach(User::factory()->create(), $organization, $role);
    $event = Event::factory()->create(['organization_id' => $organization->id]);
    $room = Room::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id]);

    return [$user, $event, $room];
}

function roomPolicyAllows(User $user, string $ability, mixed $arguments, Event $event): bool
{
    return CurrentOrganization::run(
        $event->organization,
        fn (): bool => Gate::forUser($user->unsetRelation('roles')->unsetRelation('permissions'))->allows($ability, $arguments),
    );
}

it('CA-031-6: org_admin and organizer view, create and edit rooms; only org_admin deletes', function (): void {
    foreach ([[OrganizationRole::OrgAdmin, true], [OrganizationRole::Organizer, false]] as [$role, $deletes]) {
        [$user, $event, $room] = roomPolicyScenario($role);

        expect(roomPolicyAllows($user, 'viewAny', Room::class, $event))->toBeTrue()
            ->and(roomPolicyAllows($user, 'view', $room, $event))->toBeTrue()
            ->and(roomPolicyAllows($user, 'create', [Room::class, $event], $event))->toBeTrue()
            ->and(roomPolicyAllows($user, 'update', $room, $event))->toBeTrue()
            ->and(roomPolicyAllows($user, 'delete', $room, $event))->toBe($deletes);
    }
});

it('CA-031-6: a reviewer and a member without role are denied every ability', function (?OrganizationRole $role): void {
    [$user, $event, $room] = roomPolicyScenario($role);

    expect(roomPolicyAllows($user, 'viewAny', Room::class, $event))->toBeFalse()
        ->and(roomPolicyAllows($user, 'view', $room, $event))->toBeFalse()
        ->and(roomPolicyAllows($user, 'create', [Room::class, $event], $event))->toBeFalse()
        ->and(roomPolicyAllows($user, 'update', $room, $event))->toBeFalse()
        ->and(roomPolicyAllows($user, 'delete', $room, $event))->toBeFalse();
})->with([
    'reviewer' => [OrganizationRole::Reviewer],
    'no role' => [null],
]);

it('CA-031-6: an admin of another organization is denied on the rooms of this one', function (): void {
    [, $event, $room] = roomPolicyScenario(OrganizationRole::OrgAdmin);
    [$outsider] = roomPolicyScenario(OrganizationRole::OrgAdmin);

    expect(roomPolicyAllows($outsider, 'viewAny', Room::class, $event))->toBeFalse()
        ->and(roomPolicyAllows($outsider, 'view', $room, $event))->toBeFalse()
        ->and(roomPolicyAllows($outsider, 'create', [Room::class, $event], $event))->toBeFalse()
        ->and(roomPolicyAllows($outsider, 'update', $room, $event))->toBeFalse()
        ->and(roomPolicyAllows($outsider, 'delete', $room, $event))->toBeFalse();
});
