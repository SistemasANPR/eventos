<?php

declare(strict_types=1);

use App\Actions\Organizations\InviteTeamMember;
use App\Actions\Organizations\ResendTeamInvitation;
use App\Enums\OrganizationRole;
use App\Exceptions\Domain\InvitationNotPending;
use App\Models\KnownDevice;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\MembershipAddedNotification;
use App\Notifications\SetPasswordNotification;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();
});

afterEach(function (): void {
    CurrentOrganization::forget();
});

/** @return array{0: Organization, 1: User, 2: User} organización, su administrador y un integrante con invitación pendiente */
function resendScenario(string $status = 'active'): array
{
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $member = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer, $status);
    RateLimiter::clear('team-invite:'.$admin->id);

    return [$organization, $admin, $member];
}

/** Token del último correo `team.set_password` enviado a la persona. */
function resentTokenFor(User $member): string
{
    $token = '';

    Notification::assertSentTo($member, SetPasswordNotification::class, function (SetPasswordNotification $notification) use ($member, &$token): bool {
        parse_str((string) parse_url((string) $notification->toMail($member)->actionUrl, PHP_URL_QUERY), $query);
        $token = (string) ($query['token'] ?? '');

        return true;
    });

    return $token;
}

it('CA-003-21: resending queues a new set-password email for an active member who never signed in', function (): void {
    [$organization, $admin, $member] = resendScenario();

    app(ResendTeamInvitation::class)->handle($admin, $organization, $member);

    Notification::assertSentToTimes($member, SetPasswordNotification::class, 1);
    Notification::assertNotSentTo($member, MembershipAddedNotification::class);
});

it('CA-003-21: the queued payload carries no token, which is created when the mail is built', function (): void {
    [$organization, $admin, $member] = resendScenario();
    Notification::swap(new ChannelManager(app()));
    Queue::fake();

    app(ResendTeamInvitation::class)->handle($admin, $organization, $member);

    Queue::assertPushed(SendQueuedNotifications::class, function (SendQueuedNotifications $job): bool {
        $payload = strtolower(serialize($job));

        return $job->notification instanceof SetPasswordNotification && ! str_contains($payload, 'token');
    });
    $this->assertDatabaseCount('password_reset_tokens', 0);
});

it('CA-003-21: the resent token works once and sets the password', function (): void {
    [$organization, $admin, $member] = resendScenario();

    app(ResendTeamInvitation::class)->handle($admin, $organization, $member);
    $token = resentTokenFor($member);

    $reset = fn (): string => Password::broker()->reset(
        ['email' => $member->email, 'token' => $token, 'password' => 'Clave-nueva-123!', 'password_confirmation' => 'Clave-nueva-123!'],
        function (User $user, string $password): void {
            $user->forceFill(['password' => $password])->save();
        },
    );

    expect($reset())->toBe(Password::PASSWORD_RESET)
        ->and(Hash::check('Clave-nueva-123!', $member->fresh()->password))->toBeTrue()
        ->and($reset())->toBe(Password::INVALID_TOKEN);
});

it('CA-003-21: the resent token expires within 60 minutes', function (): void {
    [$organization, $admin, $member] = resendScenario();

    app(ResendTeamInvitation::class)->handle($admin, $organization, $member);
    $token = resentTokenFor($member);

    expect((int) config('auth.passwords.users.expire'))->toBeLessThanOrEqual(60)
        ->and(Password::broker()->tokenExists($member->fresh(), $token))->toBeTrue();
    $this->travel(61)->minutes();
    expect(Password::broker()->tokenExists($member->fresh(), $token))->toBeFalse();
});

it('CA-003-29: inviting and resending share the same per-minute allowance', function (): void {
    [$organization, $admin, $member] = resendScenario();

    foreach (range(1, 9) as $i) {
        app(InviteTeamMember::class)->handle($admin, $organization, ['name' => "Persona {$i}", 'email' => "p{$i}@example.test", 'role' => 'reviewer']);
    }
    app(ResendTeamInvitation::class)->handle($admin, $organization, $member);

    expect(fn () => app(ResendTeamInvitation::class)->handle($admin, $organization, $member))
        ->toThrow(ThrottleRequestsException::class);
    expect(fn () => app(InviteTeamMember::class)->handle($admin, $organization, ['name' => 'Once', 'email' => 'p11@example.test', 'role' => 'reviewer']))
        ->toThrow(ThrottleRequestsException::class);
    Notification::assertSentToTimes($member, SetPasswordNotification::class, 1);
});

it('CA-003-26: an organizer or reviewer cannot resend and nothing is sent', function (OrganizationRole $role): void {
    [$organization, , $member] = resendScenario();
    $actor = Memberships::attach(User::factory()->create(), $organization, $role);

    expect(fn () => app(ResendTeamInvitation::class)->handle($actor, $organization, $member))
        ->toThrow(AuthorizationException::class);

    Notification::assertNothingSent();
    expect(Activity::query()->where('event', 'member_invitation_resent')->count())->toBe(0);
})->with([OrganizationRole::Organizer, OrganizationRole::Reviewer]);

it('CA-003-26: an administrator of another organization cannot resend', function (): void {
    [$organization, , $member] = resendScenario();
    $outsider = Memberships::attach(User::factory()->create(), Memberships::organization(), OrganizationRole::OrgAdmin);

    expect(fn () => app(ResendTeamInvitation::class)->handle($outsider, $organization, $member))
        ->toThrow(AuthorizationException::class);

    Notification::assertNothingSent();
});

it('CA-003-26: a deactivated administrator cannot resend', function (): void {
    [$organization, , $member] = resendScenario();
    $former = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin, 'deactivated');

    expect(fn () => app(ResendTeamInvitation::class)->handle($former, $organization, $member))
        ->toThrow(AuthorizationException::class);

    Notification::assertNothingSent();
});

it('CA-003-26: a member of another organization is never a recipient', function (): void {
    [$organization, $admin] = resendScenario();
    $outsider = Memberships::attach(User::factory()->create(), Memberships::organization(), OrganizationRole::Organizer);

    expect(fn () => app(ResendTeamInvitation::class)->handle($admin, $organization, $outsider))
        ->toThrow(AuthorizationException::class);

    Notification::assertNothingSent();
});

it('CA-003-26: a deactivated member is not a recipient', function (): void {
    [$organization, $admin, $member] = resendScenario('deactivated');

    expect(fn () => app(ResendTeamInvitation::class)->handle($admin, $organization, $member))
        ->toThrow(AuthorizationException::class);

    Notification::assertNothingSent();
});

it('Reenvio: a member who already signed in is rejected with a domain error and nothing is sent', function (): void {
    [$organization, $admin, $member] = resendScenario();
    KnownDevice::query()->create(['user_id' => $member->id, 'fingerprint_hash' => str_repeat('a', 64), 'last_seen_at' => now()]);

    expect(fn () => app(ResendTeamInvitation::class)->handle($admin, $organization, $member))
        ->toThrow(InvitationNotPending::class);

    Notification::assertNothingSent();
    expect(Activity::query()->where('event', 'member_invitation_resent')->count())->toBe(0);
});

it('CA-003-28: records the resend without secrets and changes no role or membership', function (): void {
    [$organization, $admin, $member] = resendScenario();

    app(ResendTeamInvitation::class)->handle($admin, $organization, $member);

    $entry = Activity::query()->where('event', 'member_invitation_resent')->sole();
    $json = (string) json_encode($entry->toArray());
    expect($entry->causer_id)->toBe($admin->id)
        ->and($entry->subject_id)->toBe($member->id)
        ->and($entry->properties->all())->toBe(['organization_id' => $organization->id])
        ->and($json)->not->toContain('token')
        ->and($json)->not->toContain('password');
    expect($member->fresh()->hasActiveMembershipIn($organization))->toBeTrue()
        ->and(CurrentOrganization::run($organization, fn (): array => $member->fresh()->getRoleNames()->all()))->toBe(['organizer']);
});
