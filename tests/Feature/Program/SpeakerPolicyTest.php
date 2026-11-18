<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Event;
use App\Models\Speaker;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
});

/** @return array{0: User, 1: Event, 2: Speaker} usuario con el rol, evento de su organización y un ponente */
function speakerPolicyScenario(?OrganizationRole $role): array
{
    CurrentOrganization::forget();
    $organization = Memberships::organization();
    $user = Memberships::attach(User::factory()->create(), $organization, $role);
    $event = Event::factory()->create(['organization_id' => $organization->id]);
    $speaker = Speaker::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id]);

    return [$user, $event, $speaker];
}

function speakerPolicyAllows(User $user, string $ability, mixed $arguments, Event $event): bool
{
    return CurrentOrganization::run(
        $event->organization,
        fn (): bool => Gate::forUser($user->unsetRelation('roles')->unsetRelation('permissions'))->allows($ability, $arguments),
    );
}

it('CA-021-15: org_admin and organizer view, create and edit speakers; only org_admin deletes', function (): void {
    foreach ([[OrganizationRole::OrgAdmin, true], [OrganizationRole::Organizer, false]] as [$role, $deletes]) {
        [$user, $event, $speaker] = speakerPolicyScenario($role);

        expect(speakerPolicyAllows($user, 'viewAny', Speaker::class, $event))->toBeTrue()
            ->and(speakerPolicyAllows($user, 'view', $speaker, $event))->toBeTrue()
            ->and(speakerPolicyAllows($user, 'create', [Speaker::class, $event], $event))->toBeTrue()
            ->and(speakerPolicyAllows($user, 'update', $speaker, $event))->toBeTrue()
            ->and(speakerPolicyAllows($user, 'delete', $speaker, $event))->toBe($deletes);
    }
});

it('CA-021-15: a reviewer and a member without a role can do nothing with speakers', function (?OrganizationRole $role): void {
    [$user, $event, $speaker] = speakerPolicyScenario($role);

    expect(speakerPolicyAllows($user, 'viewAny', Speaker::class, $event))->toBeFalse()
        ->and(speakerPolicyAllows($user, 'view', $speaker, $event))->toBeFalse()
        ->and(speakerPolicyAllows($user, 'create', [Speaker::class, $event], $event))->toBeFalse()
        ->and(speakerPolicyAllows($user, 'update', $speaker, $event))->toBeFalse()
        ->and(speakerPolicyAllows($user, 'delete', $speaker, $event))->toBeFalse();
})->with([[OrganizationRole::Reviewer], [null]]);

it('CA-021-15: an admin of another organization is denied everything, and without organization context too', function (): void {
    [, $event, $speaker] = speakerPolicyScenario(OrganizationRole::OrgAdmin);
    [$outsider] = speakerPolicyScenario(OrganizationRole::OrgAdmin);

    foreach (['view', 'update', 'delete'] as $ability) {
        expect(speakerPolicyAllows($outsider, $ability, $speaker, $event))->toBeFalse();
    }

    expect(speakerPolicyAllows($outsider, 'create', [Speaker::class, $event], $event))->toBeFalse();

    CurrentOrganization::forget();

    expect(Gate::forUser($outsider)->allows('viewAny', Speaker::class))->toBeFalse();
});
