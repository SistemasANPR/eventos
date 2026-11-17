<?php

declare(strict_types=1);

use App\Actions\Events\AssignUserToEvent;
use App\Actions\Events\ChangeEventAssignmentRole;
use App\Enums\EventAssignmentRole;
use App\Enums\OrganizationRole;
use App\Models\Event;
use App\Models\EventAssignment;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
});

/** @return array{0: Organization, 1: Event, 2: User, 3: User} organización con roles, evento, administrador y un integrante evaluador */
function assignmentScenario(): array
{
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $reviewer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);
    $event = Event::factory()->create(['organization_id' => $organization->id]);

    return [$organization, $event, $admin, $reviewer];
}

it('CA-011-28: an assignee only reaches the events they are assigned to', function (): void {
    [$organization, $eventX, , $reviewer] = assignmentScenario();
    $eventY = Event::factory()->create(['organization_id' => $organization->id]);
    $foreign = Event::factory()->create();
    EventAssignment::factory()->create([
        'organization_id' => $organization->id, 'event_id' => $eventX->id, 'user_id' => $reviewer->id,
    ]);

    CurrentOrganization::run($organization, function () use ($eventX, $eventY, $foreign, $reviewer): void {
        expect(Event::query()->assignedTo($reviewer)->findOrFail($eventX->id)->is($eventX))->toBeTrue()
            ->and(fn () => Event::query()->assignedTo($reviewer)->findOrFail($eventY->id))->toThrow(ModelNotFoundException::class)
            ->and(fn () => Event::query()->assignedTo($reviewer)->findOrFail($foreign->id))->toThrow(ModelNotFoundException::class);
    });
});

it('CA-011-29: an admin assigns an organization member to an event of that organization', function (): void {
    [, $event, $admin, $reviewer] = assignmentScenario();

    $assignment = app(AssignUserToEvent::class)->handle($admin, $event, ['user_id' => $reviewer->id, 'role' => 'reviewer']);

    expect($assignment->event_id)->toBe($event->id)
        ->and($assignment->user_id)->toBe($reviewer->id)
        ->and($assignment->role)->toBe(EventAssignmentRole::Reviewer)
        ->and(Activity::query()->where('event', 'event_assignment_created')->count())->toBe(1);
});

it('CA-011-29: rejects a user who is not an active member of the organization', function (string $status): void {
    [$organization, $event, $admin] = assignmentScenario();
    $outsider = User::factory()->create();
    if ($status === 'deactivated') {
        Memberships::attach($outsider, $organization, OrganizationRole::Reviewer, 'deactivated');
    }

    expect(fn () => app(AssignUserToEvent::class)->handle($admin, $event, ['user_id' => $outsider->id, 'role' => 'reviewer']))
        ->toThrow(ValidationException::class);
    expect(EventAssignment::query()->withoutGlobalScopes()->count())->toBe(0);
})->with(['not a member' => ['none'], 'deactivated member' => ['deactivated']]);

it('CA-011-29: rejects an event of another organization and a user without the permission', function (): void {
    [, , $admin, $reviewer] = assignmentScenario();
    $foreignEvent = Event::factory()->create();

    expect(fn () => app(AssignUserToEvent::class)->handle($admin, $foreignEvent, ['user_id' => $reviewer->id, 'role' => 'reviewer']))
        ->toThrow(AuthorizationException::class);

    [, $event, , $other] = assignmentScenario();
    expect(fn () => app(AssignUserToEvent::class)->handle($other, $event, ['user_id' => $other->id, 'role' => 'reviewer']))
        ->toThrow(AuthorizationException::class);
});

it('CA-011-29: an organizer without event_assignments.manage cannot assign', function (): void {
    [$organization, $event, , $reviewer] = assignmentScenario();
    $organizer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer);

    expect(fn () => app(AssignUserToEvent::class)->handle($organizer, $event, ['user_id' => $reviewer->id, 'role' => 'reviewer']))
        ->toThrow(AuthorizationException::class);
});

it('CA-011-29: the database refuses a user from another organization', function (): void {
    [$organization, $event] = assignmentScenario();
    $outsider = User::factory()->create();

    expect(fn () => EventAssignment::factory()->create([
        'organization_id' => $organization->id, 'event_id' => $event->id, 'user_id' => $outsider->id,
    ]))->toThrow(QueryException::class);
});

it('CA-011-30: the database rejects a duplicate assignment', function (): void {
    [$organization, $event, , $reviewer] = assignmentScenario();
    $attributes = ['organization_id' => $organization->id, 'event_id' => $event->id, 'user_id' => $reviewer->id, 'role' => 'reviewer'];
    EventAssignment::factory()->create($attributes);

    expect(fn () => EventAssignment::factory()->create($attributes))->toThrow(QueryException::class);
});

it('CA-011-30: the action rejects a duplicate and allows another role for the same user', function (): void {
    [, $event, $admin, $reviewer] = assignmentScenario();
    app(AssignUserToEvent::class)->handle($admin, $event, ['user_id' => $reviewer->id, 'role' => 'reviewer']);

    expect(fn () => app(AssignUserToEvent::class)->handle($admin, $event, ['user_id' => $reviewer->id, 'role' => 'reviewer']))
        ->toThrow(ValidationException::class);
    // El mismo usuario puede tener otro rol en el mismo evento.
    expect(app(AssignUserToEvent::class)->handle($admin, $event, ['user_id' => $reviewer->id, 'role' => 'coordinator'])->role)
        ->toBe(EventAssignmentRole::Coordinator);
});

it('CA-011-31: a role change is audited with who, to whom, previous and new role', function (): void {
    [$organization, $event, $admin, $reviewer] = assignmentScenario();
    $assignment = app(AssignUserToEvent::class)->handle($admin, $event, ['user_id' => $reviewer->id, 'role' => 'reviewer']);

    $changed = app(ChangeEventAssignmentRole::class)->handle($admin, $assignment, ['role' => 'coordinator']);

    $log = Activity::query()->where('event', 'event_assignment_role_changed')->sole();
    expect($changed->role)->toBe(EventAssignmentRole::Coordinator)
        ->and($log->causer_id)->toBe($admin->id)
        ->and($log->properties['user_id'])->toBe($reviewer->id)
        ->and($log->properties['old']['role'])->toBe('reviewer')
        ->and($log->properties['new']['role'])->toBe('coordinator')
        ->and($log->properties['organization_id'])->toBe($organization->id);
});

it('CA-011-31: changing to a role the user already has in the event is rejected', function (): void {
    [, $event, $admin, $reviewer] = assignmentScenario();
    $assignment = app(AssignUserToEvent::class)->handle($admin, $event, ['user_id' => $reviewer->id, 'role' => 'reviewer']);
    app(AssignUserToEvent::class)->handle($admin, $event, ['user_id' => $reviewer->id, 'role' => 'coordinator']);

    expect(fn () => app(ChangeEventAssignmentRole::class)->handle($admin, $assignment, ['role' => 'coordinator']))
        ->toThrow(ValidationException::class);
});

it('CA-011-32: the speaker role does not exist in R1', function (): void {
    [$organization, $event, $admin, $reviewer] = assignmentScenario();

    expect(fn () => app(AssignUserToEvent::class)->handle($admin, $event, ['user_id' => $reviewer->id, 'role' => 'speaker']))
        ->toThrow(ValidationException::class);
});

it('CA-011-32: the database check constraint also refuses the speaker role', function (): void {
    [$organization, $event, , $reviewer] = assignmentScenario();

    expect(fn () => DB::table('event_assignments')->insert([
        'id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'event_id' => $event->id,
        'user_id' => $reviewer->id, 'role' => 'speaker',
    ]))->toThrow(QueryException::class);
});

it('CA-011-10: only an assignee with the reviewer role is a candidate for review assignments', function (): void {
    [$organization, $event, , $reviewer] = assignmentScenario();
    $stranger = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);
    $coordinator = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);
    EventAssignment::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id, 'user_id' => $reviewer->id]);
    EventAssignment::factory()->coordinator()->create(['organization_id' => $organization->id, 'event_id' => $event->id, 'user_id' => $coordinator->id]);

    CurrentOrganization::run($organization, function () use ($event, $reviewer, $stranger, $coordinator): void {
        expect(EventAssignment::isReviewerOf($event, $reviewer))->toBeTrue()
            ->and(EventAssignment::isReviewerOf($event, $stranger))->toBeFalse()
            ->and(EventAssignment::isReviewerOf($event, $coordinator))->toBeFalse();
    });
});
