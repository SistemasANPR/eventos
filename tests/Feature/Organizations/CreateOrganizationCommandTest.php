<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Notifications\SetPasswordNotification;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\PendingCommand;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
});

function createOrganizationCommand(string $slug = 'acme-eventos', string $email = 'admin@example.test'): PendingCommand
{
    $command = artisan('app:create-organization', [
        'slug' => $slug,
        'name' => 'Acme Eventos',
        '--admin-email' => $email,
        '--admin-name' => 'Ana Admin',
    ]);
    assert($command instanceof PendingCommand);

    return $command;
}

it('CA-001-14: creates the organization, its first org_admin, queues the password email and audits', function (): void {
    Notification::fake();

    createOrganizationCommand()->assertSuccessful();

    $organization = Organization::query()->where('slug', 'acme-eventos')->sole();
    $admin = User::query()->where('email', 'admin@example.test')->sole();
    expect($admin->hasActiveMembershipIn($organization))->toBeTrue()
        ->and(CurrentOrganization::has())->toBeFalse()
        ->and(CurrentOrganization::run($organization, fn () => $admin->unsetRelation('roles')->getRoleNames()->all()))->toBe(['org_admin'])
        ->and(CurrentOrganization::run($organization, fn () => Role::query()->count()))->toBe(3)
        ->and(Activity::query()->where('event', 'organization_created')->count())->toBe(1);
    Notification::assertSentTo($admin, SetPasswordNotification::class);
});

it('CA-001-14: reuses an existing user by email as the administrator', function (): void {
    Notification::fake();
    $existing = User::factory()->create(['email' => 'admin@example.test']);

    createOrganizationCommand()->assertSuccessful();

    $organization = Organization::query()->where('slug', 'acme-eventos')->sole();
    expect(User::query()->count())->toBe(1)
        ->and($existing->hasActiveMembershipIn($organization))->toBeTrue();
});

it('CA-001-14: an existing slug is not duplicated, reports the error and exits non-zero', function (): void {
    Notification::fake();
    createOrganizationCommand()->assertSuccessful();

    createOrganizationCommand('acme-eventos', 'otro@example.test')->assertFailed();

    expect(Organization::query()->count())->toBe(1)
        ->and(User::query()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'organization_created')->count())->toBe(1);
    Notification::assertSentTimes(SetPasswordNotification::class, 1);
});

it('CA-001-15: an invalid slug creates nothing', function (string $slug): void {
    Notification::fake();

    createOrganizationCommand($slug)->assertFailed();

    expect(Organization::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(0);
    Notification::assertNothingSent();
})->with(['uppercase' => 'Acme', 'reserved' => 'admin', 'spaces' => 'a b c', 'too short' => 'ab']);

it('CA-001-15: an invalid admin email creates nothing', function (): void {
    Notification::fake();

    createOrganizationCommand('acme-eventos', 'no-es-correo')->assertFailed();

    expect(Organization::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('CA-001-14: a failure after creating the organization rolls everything back', function (): void {
    Notification::fake();
    // Falla el alta del administrador: la organización y los roles ya creados deben revertirse.
    Event::listen('eloquent.creating: '.User::class, fn () => throw new RuntimeException('boom'));

    expect(fn () => createOrganizationCommand()->run())->toThrow(RuntimeException::class);

    expect(Organization::query()->count())->toBe(0)->and(User::query()->count())->toBe(0);
    Notification::assertNothingSent();
});
