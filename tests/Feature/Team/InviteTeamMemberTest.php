<?php

declare(strict_types=1);

use App\Actions\Organizations\InviteTeamMember;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\MembershipAddedNotification;
use App\Notifications\SetPasswordNotification;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Spatie\Permission\Models\Role;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();
});

afterEach(function (): void {
    CurrentOrganization::forget();
});

/** @return array{0: Organization, 1: User} organización con su administrador */
function teamOrganizationWithAdmin(): array
{
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);

    return [$organization, $admin];
}

/** @return list<string> */
function teamRolesIn(Organization $organization, User $user): array
{
    return CurrentOrganization::run($organization, fn (): array => $user->fresh()->getRoleNames()->sort()->values()->all());
}

it('CA-003-21: creates the user with an active membership and the chosen role, and queues the set-password link', function (string $role): void {
    [$organization, $admin] = teamOrganizationWithAdmin();

    app(InviteTeamMember::class)->handle($admin, $organization, ['name' => 'Nueva Persona', 'email' => 'nueva@example.test', 'role' => $role]);

    $member = User::query()->where('email', 'nueva@example.test')->sole();
    expect($member->name)->toBe('Nueva Persona')
        ->and($member->hasActiveMembershipIn($organization))->toBeTrue()
        ->and(teamRolesIn($organization, $member))->toBe([$role]);
    Notification::assertSentTo($member, SetPasswordNotification::class);
    Notification::assertNotSentTo($member, MembershipAddedNotification::class);
})->with(['org_admin', 'organizer', 'reviewer']);

it('CA-003-21: the queued link carries a one-time token that expires in at most 60 minutes', function (): void {
    [$organization, $admin] = teamOrganizationWithAdmin();

    app(InviteTeamMember::class)->handle($admin, $organization, ['name' => 'Ana', 'email' => 'ana@example.test', 'role' => 'organizer']);

    $member = User::query()->where('email', 'ana@example.test')->sole();
    Notification::assertSentTo($member, SetPasswordNotification::class, function (SetPasswordNotification $notification) use ($member): bool {
        $url = (string) $notification->toMail($member)->actionUrl;
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $token = (string) ($query['token'] ?? '');

        return Password::broker()->tokenExists($member, $token) && Password::broker()->getRepository()->exists($member, $token);
    });
    expect((int) config('auth.passwords.users.expire'))->toBeLessThanOrEqual(60);
});

it('CA-003-21: stores the email in lowercase and without spaces', function (): void {
    [$organization, $admin] = teamOrganizationWithAdmin();

    app(InviteTeamMember::class)->handle($admin, $organization, ['name' => 'Ana', 'email' => '  Ana@Example.TEST ', 'role' => 'organizer']);

    expect(User::query()->where('email', 'ana@example.test')->exists())->toBeTrue();
});

it('CA-003-21: the invited new user starts with a random password nobody knows', function (): void {
    [$organization, $admin] = teamOrganizationWithAdmin();

    app(InviteTeamMember::class)->handle($admin, $organization, ['name' => 'Ana', 'email' => 'ana@example.test', 'role' => 'organizer']);

    $member = User::query()->where('email', 'ana@example.test')->sole();
    expect(strlen($member->password))->toBeGreaterThan(30)
        ->and(Hash::check('', $member->password))->toBeFalse();
});

it('CA-003-22: attaches the membership of a user from another organization without copying data or revealing it', function (): void {
    [$organization, $admin] = teamOrganizationWithAdmin();
    $other = Memberships::organization();
    $existing = Memberships::attach(User::factory()->create(['name' => 'Nombre Original', 'email' => 'viva@example.test']), $other, OrganizationRole::Reviewer);

    app(InviteTeamMember::class)->handle($admin, $organization, ['name' => 'Otro Nombre', 'email' => 'VIVA@example.test', 'role' => 'organizer']);

    $existing->refresh();
    expect($existing->name)->toBe('Nombre Original')
        ->and($existing->hasActiveMembershipIn($organization))->toBeTrue()
        ->and($existing->hasActiveMembershipIn($other))->toBeTrue()
        ->and(teamRolesIn($organization, $existing))->toBe(['organizer'])
        ->and(teamRolesIn($other, $existing))->toBe(['reviewer'])
        ->and(User::query()->count())->toBe(2);
    Notification::assertSentTo($existing, MembershipAddedNotification::class);
    Notification::assertNotSentTo($existing, SetPasswordNotification::class);
});

it('CA-003-22: the outcome for the administrator is identical for a new and an existing email', function (): void {
    [$organization, $admin] = teamOrganizationWithAdmin();
    Memberships::attach(User::factory()->create(['email' => 'viva@example.test']), Memberships::organization(), OrganizationRole::Reviewer);
    $action = app(InviteTeamMember::class);

    // Ninguna de las dos altas lanza ni devuelve nada que las distinga.
    $action->handle($admin, $organization, ['name' => 'Nueva', 'email' => 'nueva@example.test', 'role' => 'reviewer']);
    $action->handle($admin, $organization, ['name' => 'Viva', 'email' => 'viva@example.test', 'role' => 'reviewer']);

    $entries = Activity::query()->where('event', 'member_invited')->get();
    expect($entries)->toHaveCount(2)
        ->and($entries[0]->properties->keys()->all())->toBe($entries[1]->properties->keys()->all());
});

it('CA-003-23: rejects an email with an invalid format and sends nothing', function (string $email): void {
    [$organization, $admin] = teamOrganizationWithAdmin();

    expect(fn () => app(InviteTeamMember::class)->handle($admin, $organization, ['name' => 'Ana', 'email' => $email, 'role' => 'organizer']))
        ->toThrow(ValidationException::class);

    expect(User::query()->count())->toBe(1);
    Notification::assertNothingSent();
})->with(['not-an-email', '', 'a@', '@example.test']);

it('CA-003-23: rejects an email that already belongs to the organization, active or not, and sends nothing', function (string $status): void {
    [$organization, $admin] = teamOrganizationWithAdmin();
    Memberships::attach(User::factory()->create(['email' => 'dentro@example.test']), $organization, OrganizationRole::Reviewer, $status);

    try {
        app(InviteTeamMember::class)->handle($admin, $organization, ['name' => 'Dentro', 'email' => 'DENTRO@example.test', 'role' => 'organizer']);
        $this->fail('Debió rechazar el alta.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('email');
    }

    Notification::assertNothingSent();
    expect(teamRolesIn($organization, User::query()->where('email', 'dentro@example.test')->sole()))->toBe(['reviewer']);
})->with(['active', 'deactivated']);

it('CA-003-23: requires name, email and role', function (): void {
    [$organization, $admin] = teamOrganizationWithAdmin();

    try {
        app(InviteTeamMember::class)->handle($admin, $organization, []);
        $this->fail('Debió rechazar el alta.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toEqualCanonicalizing(['name', 'email', 'role']);
    }
});

it('CA-003-26: refuses the invitation to an organizer or a reviewer and changes nothing', function (OrganizationRole $role): void {
    $organization = Memberships::organization();
    $actor = Memberships::attach(User::factory()->create(), $organization, $role);

    expect(fn () => app(InviteTeamMember::class)->handle($actor, $organization, ['name' => 'Ana', 'email' => 'ana@example.test', 'role' => 'organizer']))
        ->toThrow(AuthorizationException::class);

    expect(User::query()->count())->toBe(1)
        ->and(Activity::query()->count())->toBe(0);
    Notification::assertNothingSent();
})->with([OrganizationRole::Organizer, OrganizationRole::Reviewer]);

it('CA-003-26: an administrator of another organization cannot invite into this one', function (): void {
    [$organizationA] = teamOrganizationWithAdmin();
    [, $adminOfB] = teamOrganizationWithAdmin();

    expect(fn () => app(InviteTeamMember::class)->handle($adminOfB, $organizationA, ['name' => 'Ana', 'email' => 'ana@example.test', 'role' => 'organizer']))
        ->toThrow(AuthorizationException::class);

    Notification::assertNothingSent();
});

it('CA-003-26: a deactivated administrator cannot invite', function (): void {
    $organization = Memberships::organization();
    $actor = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin, 'deactivated');

    expect(fn () => app(InviteTeamMember::class)->handle($actor, $organization, ['name' => 'Ana', 'email' => 'ana@example.test', 'role' => 'organizer']))
        ->toThrow(AuthorizationException::class);
});

it('CA-003-28: records who invited whom with which role, without secrets', function (): void {
    [$organization, $admin] = teamOrganizationWithAdmin();

    app(InviteTeamMember::class)->handle($admin, $organization, ['name' => 'Ana', 'email' => 'ana@example.test', 'role' => 'reviewer']);

    $member = User::query()->where('email', 'ana@example.test')->sole();
    $entry = Activity::query()->where('event', 'member_invited')->sole();
    expect($entry->causer_id)->toBe($admin->id)
        ->and($entry->subject_id)->toBe($member->id)
        ->and($entry->properties->all())->toBe(['organization_id' => $organization->id, 'role' => 'reviewer'])
        ->and(json_encode($entry->toArray()))->not->toContain('password');
    expect(json_encode($entry->toArray()))->not->toContain('token');
});

it('CA-003-29: answers 429 on the eleventh invitation within a minute and sends no more mail', function (): void {
    [$organization, $admin] = teamOrganizationWithAdmin();
    RateLimiter::clear('team-invite:'.$admin->id);
    $action = app(InviteTeamMember::class);

    foreach (range(1, 10) as $i) {
        $action->handle($admin, $organization, ['name' => "Persona {$i}", 'email' => "p{$i}@example.test", 'role' => 'reviewer']);
    }

    expect(fn () => $action->handle($admin, $organization, ['name' => 'Once', 'email' => 'p11@example.test', 'role' => 'reviewer']))
        ->toThrow(ThrottleRequestsException::class);

    expect(User::query()->where('email', 'p11@example.test')->exists())->toBeFalse();
    Notification::assertSentTimes(SetPasswordNotification::class, 10);
});

it('CA-003-29: the limit is per user, not shared between administrators', function (): void {
    [$organization, $admin] = teamOrganizationWithAdmin();
    $second = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    RateLimiter::clear('team-invite:'.$admin->id);
    RateLimiter::clear('team-invite:'.$second->id);
    $action = app(InviteTeamMember::class);

    foreach (range(1, 10) as $i) {
        $action->handle($admin, $organization, ['name' => "Persona {$i}", 'email' => "p{$i}@example.test", 'role' => 'reviewer']);
    }

    $action->handle($second, $organization, ['name' => 'Otra', 'email' => 'otra@example.test', 'role' => 'reviewer']);

    expect(User::query()->where('email', 'otra@example.test')->exists())->toBeTrue();
});

it('CA-003-29: the limit releases after a minute', function (): void {
    [$organization, $admin] = teamOrganizationWithAdmin();
    RateLimiter::clear('team-invite:'.$admin->id);
    $action = app(InviteTeamMember::class);
    foreach (range(1, 10) as $i) {
        $action->handle($admin, $organization, ['name' => "Persona {$i}", 'email' => "p{$i}@example.test", 'role' => 'reviewer']);
    }

    $this->travel(61)->seconds();
    $action->handle($admin, $organization, ['name' => 'Once', 'email' => 'p11@example.test', 'role' => 'reviewer']);

    expect(User::query()->where('email', 'p11@example.test')->exists())->toBeTrue();
});

it('CA-003-30: rejects the platform role, an unknown role and the platform admin flag, and changes nothing', function (array $extra): void {
    [$organization, $admin] = teamOrganizationWithAdmin();
    $input = ['name' => 'Ana', 'email' => 'ana@example.test', 'role' => 'organizer', ...$extra];

    expect(fn () => app(InviteTeamMember::class)->handle($admin, $organization, $input))
        ->toThrow(ValidationException::class);

    expect(User::query()->count())->toBe(1);
    Notification::assertNothingSent();
})->with([
    'platform role' => [['role' => 'platform']],
    'unknown role' => [['role' => 'superuser']],
    'platform flag' => [['is_platform_admin' => true]],
]);

it('CA-003-30: a failure in the second step leaves no user behind', function (): void {
    [$organization, $admin] = teamOrganizationWithAdmin();
    // Con el rol borrado, la asignación falla después de crear al usuario y la membresía.
    CurrentOrganization::run($organization, fn () => Role::query()->where('name', 'reviewer')->delete());

    expect(fn () => app(InviteTeamMember::class)->handle($admin, $organization, ['name' => 'Ana', 'email' => 'ana@example.test', 'role' => 'reviewer']))
        ->toThrow(RoleDoesNotExist::class);

    expect(User::query()->where('email', 'ana@example.test')->exists())->toBeFalse();
    Notification::assertNothingSent();
});
