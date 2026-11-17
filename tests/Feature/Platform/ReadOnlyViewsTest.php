<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\OrganizationRole;
use App\Filament\Platform\Resources\AuditLog\AuditLogResource;
use App\Filament\Platform\Resources\AuditLog\Pages\ListAuditLog;
use App\Filament\Platform\Resources\Events\EventResource;
use App\Filament\Platform\Resources\Events\Pages\ListEvents;
use App\Filament\Platform\Resources\Users\Pages\ListUsers;
use App\Filament\Platform\Resources\Users\Pages\ViewUser;
use App\Filament\Platform\Resources\Users\UserResource;
use App\Models\Activity;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\Support\Memberships;
use Tests\Support\PlatformTables;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel('platform');
});

afterEach(function (): void {
    CurrentOrganization::forget();
});

function actingAsPlatform(): User
{
    $platform = User::factory()->platformAdmin()->create();
    Auth::login($platform);

    return $platform;
}

/* ---------------------------------------------------------------- Usuarios */

it('CA-016-19: a platform user sees the users of every organization, searches them and sees the platform flag and the active organizations', function (): void {
    actingAsPlatform();
    $ana = Memberships::attach(User::factory()->create(['name' => 'Ana Admin', 'email' => 'ana@a.example']), Memberships::organization(), OrganizationRole::OrgAdmin);
    $beto = Memberships::attach(User::factory()->create(['name' => 'Beto Revisor', 'email' => 'beto@b.example']), Memberships::organization(), OrganizationRole::Reviewer);

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$ana, $beto])
        ->assertTableColumnStateSet('active_organizations_count', 1, $ana)
        ->searchTable('beto@b.example')
        ->assertCanSeeTableRecords([$beto])
        ->assertCanNotSeeTableRecords([$ana]);
});

it('CA-016-19: the list is paginated with a maximum of 100 per page', function (): void {
    actingAsPlatform();

    expect(PlatformTables::maxPageSize(Livewire::test(ListUsers::class)))->toBeLessThanOrEqual(100);
});

it('CA-016-20: the list and the detail never contain the password hash, the remember token or 2FA secrets', function (): void {
    actingAsPlatform();
    $user = Memberships::attach(User::factory()->create(), Memberships::organization(), OrganizationRole::OrgAdmin);
    $user->forceFill(['remember_token' => 'remember-token-secret-value'])->save();

    foreach ([Livewire::test(ListUsers::class), Livewire::test(ViewUser::class, ['record' => $user->getRouteKey()])] as $page) {
        $page->assertDontSee($user->password)->assertDontSee('remember-token-secret-value');
    }
});

it('CA-016-21: a user with memberships in A and B appears once, with both memberships in the detail', function (): void {
    actingAsPlatform();
    $organizationA = Memberships::organization();
    $organizationB = Memberships::organization();
    $user = Memberships::attach(User::factory()->create(), $organizationA, OrganizationRole::OrgAdmin);
    Memberships::attach($user, $organizationB, OrganizationRole::Reviewer);

    $list = Livewire::test(ListUsers::class)->assertCanSeeTableRecords([$user]);
    expect(PlatformTables::records($list)->where('id', $user->id))->toHaveCount(1);

    Livewire::test(ViewUser::class, ['record' => $user->getRouteKey()])
        ->assertSee($organizationA->name)->assertSee($organizationB->name)
        ->assertSee('org_admin')->assertSee('reviewer');
});

it('CA-016-22: the users screens are read only and nothing can set is_platform_admin', function (): void {
    actingAsPlatform();
    $user = User::factory()->create();

    Livewire::test(ListUsers::class)
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete')
        ->assertActionDoesNotExist('create');

    expect(array_keys(UserResource::getPages()))->toBe(['index', 'view'])
        ->and(UserResource::canCreate())->toBeFalse()
        ->and(UserResource::canEdit($user))->toBeFalse()
        ->and(UserResource::canDelete($user))->toBeFalse()
        ->and((new User)->getFillable())->not->toContain('is_platform_admin');
});

it('CA-016-2: the users screens are forbidden to a member of an organization', function (): void {
    $this->actingAs(Memberships::attach(User::factory()->create(), Memberships::organization(), OrganizationRole::OrgAdmin));

    Livewire::test(ListUsers::class)->assertForbidden();
    Livewire::test(ListEvents::class)->assertForbidden();
    Livewire::test(ListAuditLog::class)->assertForbidden();
});

/* ----------------------------------------------------------------- Eventos */

it('CA-016-23: a platform user sees the events of every organization, those of a suspended one included and the deleted ones excluded', function (): void {
    actingAsPlatform();
    $organizationA = Memberships::organization();
    $suspended = Organization::factory()->create(['status' => 'suspended']);
    $eventA = Event::factory()->create(['organization_id' => $organizationA->id]);
    $eventSuspended = Event::factory()->create(['organization_id' => $suspended->id, 'status' => EventStatus::Published]);
    $deleted = Event::factory()->create(['organization_id' => $organizationA->id]);
    CurrentOrganization::run($organizationA, fn () => $deleted->delete());

    Livewire::test(ListEvents::class)
        ->assertCanSeeTableRecords([$eventA, $eventSuspended])
        ->assertCanNotSeeTableRecords([$deleted])
        ->filterTable('organization_id', $suspended->id)
        ->assertCanSeeTableRecords([$eventSuspended])
        ->assertCanNotSeeTableRecords([$eventA])
        ->removeTableFilters()
        ->filterTable('status', EventStatus::Published->value)
        ->assertCanSeeTableRecords([$eventSuspended])
        ->assertCanNotSeeTableRecords([$eventA]);
});

it('CA-016-23: the list is paginated with a maximum of 100 per page', function (): void {
    actingAsPlatform();

    expect(PlatformTables::maxPageSize(Livewire::test(ListEvents::class)))->toBeLessThanOrEqual(100);
});

it('CA-016-24: the events screen is read only', function (): void {
    actingAsPlatform();
    $event = Event::factory()->create();

    Livewire::test(ListEvents::class)
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('publish')
        ->assertTableActionDoesNotExist('archive')
        ->assertActionDoesNotExist('create');

    expect(array_keys(EventResource::getPages()))->toBe(['index'])
        ->and(EventResource::canCreate())->toBeFalse()
        ->and(EventResource::canEdit($event))->toBeFalse()
        ->and(EventResource::canDelete($event))->toBeFalse();
});

it('CA-016-25: loading the events list writes bypass records per query, not per row, with actor, model and panel', function (): void {
    $platform = actingAsPlatform();
    Event::factory()->count(6)->create();

    Livewire::test(ListEvents::class)->assertSuccessful();

    $entries = Activity::query()->where('event', 'organization_scope_bypassed')->get();
    expect($entries->count())->toBeGreaterThan(0)->toBeLessThan(6)
        ->and($entries->pluck('causer_id')->unique()->all())->toBe([$platform->id])
        ->and($entries->pluck('properties.model')->unique()->all())->toBe([Event::class])
        ->and($entries->pluck('properties.panel')->unique()->all())->toBe(['platform']);
});

it('CA-016-25: a user who is not from the platform cannot skip the organization filter', function (): void {
    $user = Memberships::attach(User::factory()->create(), Memberships::organization(), OrganizationRole::OrgAdmin);
    $this->actingAs($user);

    expect(fn () => Event::query()->withoutOrganizationScope()->get())->toThrow(AuthorizationException::class);
});

/* --------------------------------------------------------------- Auditoría */

it('CA-016-26: a platform user sees the audit of every organization, newest first, and filters it', function (): void {
    actingAsPlatform();
    $organizationA = Memberships::organization();
    $organizationB = Memberships::organization();
    $actor = User::factory()->create(['name' => 'Persona Actora']);
    $old = Activity::query()->create(['log_name' => 'default', 'description' => 'primero', 'event' => 'first', 'organization_id' => $organizationA->id, 'causer_type' => User::class, 'causer_id' => $actor->id, 'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00']);
    $new = Activity::query()->create(['log_name' => 'default', 'description' => 'segundo', 'event' => 'second', 'organization_id' => $organizationB->id, 'created_at' => '2026-11-05 10:00:00', 'updated_at' => '2026-11-05 10:00:00']);

    Livewire::test(ListAuditLog::class)
        ->assertCanSeeTableRecords([$new, $old], inOrder: true)
        ->filterTable('organization_id', $organizationA->id)
        ->assertCanSeeTableRecords([$old])->assertCanNotSeeTableRecords([$new])
        ->removeTableFilters()
        ->filterTable('event', 'second')
        ->assertCanSeeTableRecords([$new])->assertCanNotSeeTableRecords([$old])
        ->removeTableFilters()
        ->filterTable('causer_id', $actor->id)
        ->assertCanSeeTableRecords([$old])->assertCanNotSeeTableRecords([$new])
        ->removeTableFilters()
        ->filterTable('created_at', ['from' => '2026-11-04', 'until' => '2026-11-06'])
        ->assertCanSeeTableRecords([$new])->assertCanNotSeeTableRecords([$old]);
});

it('CA-016-26: the list is paginated with a maximum of 100 per page', function (): void {
    actingAsPlatform();

    expect(PlatformTables::maxPageSize(Livewire::test(ListAuditLog::class)))->toBeLessThanOrEqual(100);
});

it('CA-016-27: an access record shows only event, date and actor, never the IP or the email hash', function (): void {
    actingAsPlatform();
    Activity::query()->create([
        'log_name' => 'auth', 'description' => 'login_throttled', 'event' => 'login_throttled',
        'properties' => ['ip' => '203.0.113.77', 'email_hash' => 'abcdef0123456789hash', 'device' => 'Firefox en Windows'],
    ]);

    Livewire::test(ListAuditLog::class)
        ->assertSee('login_throttled')
        ->assertDontSee('203.0.113.77')
        ->assertDontSee('abcdef0123456789hash')
        ->assertDontSee('Firefox en Windows');
});

it('CA-016-27: the other records show the old and new values and the reason', function (): void {
    actingAsPlatform();
    Activity::query()->create([
        'log_name' => 'default', 'description' => 'organization_suspended', 'event' => 'organization_suspended',
        'properties' => ['reason' => 'Falta de pago prolongada', 'old' => ['status' => 'active'], 'new' => ['status' => 'suspended'], 'secret_note' => 'no-debe-verse'],
    ]);

    Livewire::test(ListAuditLog::class)
        ->assertSee('status: active → suspended')
        ->assertSee('Falta de pago prolongada')
        ->assertDontSee('no-debe-verse');
});

it('CA-016-28: the audit viewer is read only', function (): void {
    actingAsPlatform();
    $entry = Activity::query()->create(['log_name' => 'default', 'description' => 'x', 'event' => 'x']);

    Livewire::test(ListAuditLog::class)
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('delete');

    expect(array_keys(AuditLogResource::getPages()))->toBe(['index'])
        ->and(AuditLogResource::canCreate())->toBeFalse()
        ->and(AuditLogResource::canEdit($entry))->toBeFalse()
        ->and(AuditLogResource::canDelete($entry))->toBeFalse();
});

it('CA-016-30: the bypass records are visible and filterable, with who looked at which model', function (): void {
    $platform = actingAsPlatform();
    Event::factory()->create();
    Livewire::test(ListEvents::class)->assertSuccessful();
    $bypass = Activity::query()->where('event', 'organization_scope_bypassed')->firstOrFail();

    Livewire::test(ListAuditLog::class)
        ->filterTable('event', 'organization_scope_bypassed')
        ->assertCanSeeTableRecords([$bypass])
        ->assertSee($platform->name)
        ->assertSee(Event::class);
});
