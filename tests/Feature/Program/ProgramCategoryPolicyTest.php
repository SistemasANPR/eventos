<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\ProgramCategoryKind;
use App\Models\ActivityCategory;
use App\Models\Event;
use App\Models\SpeakerCategory;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
});

/**
 * @return array{0: User, 1: Event, 2: SpeakerCategory|ActivityCategory} usuario con el rol, evento de su organización y una categoría
 */
function policyScenario(ProgramCategoryKind $kind, ?OrganizationRole $role): array
{
    $organization = Memberships::organization();
    $user = Memberships::attach(User::factory()->create(), $organization, $role);
    $event = Event::factory()->create(['organization_id' => $organization->id]);
    $category = $kind->modelClass()::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id]);

    return [$user, $event, $category];
}

/** @return bool resultado de la Policy dentro de la organización del recurso */
function policyAllows(User $user, string $ability, mixed $arguments, Event $event): bool
{
    return CurrentOrganization::run(
        $event->organization,
        fn (): bool => Gate::forUser($user->unsetRelation('roles')->unsetRelation('permissions'))->allows($ability, $arguments),
    );
}

dataset('kinds', [
    'speaker categories' => [ProgramCategoryKind::Speaker],
    'activity categories' => [ProgramCategoryKind::Activity],
]);

it('CA-021-15: org_admin and organizer view, create and edit categories; only org_admin deletes', function (ProgramCategoryKind $kind): void {
    foreach ([[OrganizationRole::OrgAdmin, true], [OrganizationRole::Organizer, false]] as [$role, $deletes]) {
        [$user, $event, $category] = policyScenario($kind, $role);
        $model = $kind->modelClass();

        expect(policyAllows($user, 'viewAny', $model, $event))->toBeTrue()
            ->and(policyAllows($user, 'view', $category, $event))->toBeTrue()
            ->and(policyAllows($user, 'create', [$model, $event], $event))->toBeTrue()
            ->and(policyAllows($user, 'update', $category, $event))->toBeTrue()
            ->and(policyAllows($user, 'delete', $category, $event))->toBe($deletes);
    }
})->with('kinds');

it('CA-021-15: a reviewer and a member without role are denied every ability', function (ProgramCategoryKind $kind, ?OrganizationRole $role): void {
    [$user, $event, $category] = policyScenario($kind, $role);
    $model = $kind->modelClass();

    expect(policyAllows($user, 'viewAny', $model, $event))->toBeFalse()
        ->and(policyAllows($user, 'view', $category, $event))->toBeFalse()
        ->and(policyAllows($user, 'create', [$model, $event], $event))->toBeFalse()
        ->and(policyAllows($user, 'update', $category, $event))->toBeFalse()
        ->and(policyAllows($user, 'delete', $category, $event))->toBeFalse();
})->with('kinds')->with([
    'reviewer' => [OrganizationRole::Reviewer],
    'no role' => [null],
]);

it('CA-021-15: an admin of another organization is denied on categories of this one', function (ProgramCategoryKind $kind): void {
    [, $event, $category] = policyScenario($kind, OrganizationRole::OrgAdmin);
    [$outsider] = policyScenario($kind, OrganizationRole::OrgAdmin);
    $model = $kind->modelClass();

    // Aunque el contexto sea el de la organización del recurso, la membresía y el permiso son de otra.
    expect(policyAllows($outsider, 'viewAny', $model, $event))->toBeFalse()
        ->and(policyAllows($outsider, 'view', $category, $event))->toBeFalse()
        ->and(policyAllows($outsider, 'create', [$model, $event], $event))->toBeFalse()
        ->and(policyAllows($outsider, 'update', $category, $event))->toBeFalse()
        ->and(policyAllows($outsider, 'delete', $category, $event))->toBeFalse();
})->with('kinds');

it('CA-021-15: the speaker and activity permissions are independent', function (): void {
    [$user, $event, $speakerCategory] = policyScenario(ProgramCategoryKind::Speaker, OrganizationRole::OrgAdmin);
    $activityCategory = ActivityCategory::factory()->create(['organization_id' => $event->organization_id, 'event_id' => $event->id]);

    // Sin `speakers.manage` ni `speakers.delete`, un rol con solo los permisos de actividades no toca ponentes.
    CurrentOrganization::run($event->organization, function () use ($user): void {
        $user->unsetRelation('roles')->unsetRelation('permissions');
        $role = $user->roles()->first();
        $role?->revokePermissionTo(['speakers.manage', 'speakers.delete']);
    });

    expect(policyAllows($user, 'update', $speakerCategory, $event))->toBeFalse()
        ->and(policyAllows($user, 'delete', $speakerCategory, $event))->toBeFalse()
        ->and(policyAllows($user, 'update', $activityCategory, $event))->toBeTrue();
});
