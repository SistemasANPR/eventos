<?php

declare(strict_types=1);

use App\Actions\Organizations\ReactivateOrganization;
use App\Actions\Organizations\SuspendOrganization;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Filament\Platform\Resources\Organizations\Pages\ListOrganizations;
use App\Filament\Platform\Resources\Organizations\Pages\ViewOrganization;
use App\Models\Activity;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\SetPasswordNotification;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
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

function platformSession(): User
{
    $platform = User::factory()->platformAdmin()->create();
    Auth::login($platform);

    return $platform;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function newOrganizationData(array $overrides = []): array
{
    return array_merge([
        'slug' => 'nueva-asociacion',
        'name' => 'Nueva Asociación',
        'admin_email' => 'directora@nueva.example',
        'admin_name' => 'Directora Nueva',
    ], $overrides);
}

it('CA-016-9: a platform user sees every organization, active and suspended, with member and event counts', function (): void {
    platformSession();
    $active = Memberships::organization();
    $suspended = Organization::factory()->create(['status' => OrganizationStatus::Suspended]);
    Memberships::attach(User::factory()->create(), $active, OrganizationRole::OrgAdmin);
    Memberships::attach(User::factory()->create(), $active, OrganizationRole::Organizer);
    Memberships::attach(User::factory()->create(), $active, OrganizationRole::Reviewer, 'deactivated');
    Event::factory()->count(2)->create(['organization_id' => $active->id]);

    Livewire::test(ListOrganizations::class)
        ->assertCanSeeTableRecords([$active, $suspended])
        ->assertTableColumnStateSet('active_members_count', 2, $active)
        ->assertTableColumnStateSet('events_count', 2, $active)
        ->assertTableColumnStateSet('events_count', 0, $suspended);
});

it('CA-016-9: the list is paginated with a maximum of 100 per page', function (): void {
    platformSession();

    expect(PlatformTables::maxPageSize(Livewire::test(ListOrganizations::class)))->toBeLessThanOrEqual(100);
});

it('CA-016-10: the detail shows the administrators and counts, and nothing of the organization content', function (): void {
    platformSession();
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(['name' => 'Ana Admin', 'email' => 'ana@admin.example']), $organization, OrganizationRole::OrgAdmin);
    Memberships::attach(User::factory()->create(['email' => 'otro@organizador.example']), $organization, OrganizationRole::Organizer);

    $page = Livewire::test(ViewOrganization::class, ['record' => $organization->getRouteKey()]);

    $page->assertSee('Ana Admin')->assertSee('ana@admin.example')->assertDontSee('otro@organizador.example');
    expect($admin->exists)->toBeTrue();
});

it('CA-016-11: a platform user creates an organization with its first administrator, like the command does', function (): void {
    Notification::fake();
    platformSession();

    Livewire::test(ListOrganizations::class)
        ->callAction(TestAction::make('createOrganization'), newOrganizationData())
        ->assertHasNoFormErrors();

    $organization = Organization::query()->where('slug', 'nueva-asociacion')->sole();
    $admin = User::query()->where('email', 'directora@nueva.example')->sole();

    expect($organization->name)->toBe('Nueva Asociación')
        ->and($admin->organizations()->whereKey($organization->id)->wherePivot('status', 'active')->exists())->toBeTrue();
    CurrentOrganization::run($organization, fn () => expect($admin->fresh()->hasRole(OrganizationRole::OrgAdmin->value))->toBeTrue());
    Notification::assertSentTo($admin, SetPasswordNotification::class);
    expect(Activity::query()->where('event', 'organization_created')->count())->toBe(1);
});

it('CA-016-12: an invalid slug, a reserved one or a bad email are refused field by field and create nothing', function (array $overrides, string $field): void {
    Notification::fake();
    platformSession();

    Livewire::test(ListOrganizations::class)
        ->callAction(TestAction::make('createOrganization'), newOrganizationData($overrides))
        ->assertHasFormErrors([$field]);

    expect(Organization::query()->where('slug', '!=', 'ignored')->count())->toBe(0);
})->with([
    'reserved slug platform' => [['slug' => 'platform'], 'slug'],
    'reserved slug admin' => [['slug' => 'admin'], 'slug'],
    'invalid slug' => [['slug' => 'Con Espacios'], 'slug'],
    'invalid email' => [['admin_email' => 'no-es-correo'], 'admin_email'],
    'missing name' => [['name' => ''], 'name'],
]);

it('CA-016-12: a slug that already exists, even in a deleted organization, creates nothing', function (): void {
    Notification::fake();
    platformSession();
    Organization::factory()->create(['slug' => 'ya-existe'])->delete();

    Livewire::test(ListOrganizations::class)
        ->callAction(TestAction::make('createOrganization'), newOrganizationData(['slug' => 'ya-existe']));

    expect(Organization::withTrashed()->where('slug', 'ya-existe')->count())->toBe(1)
        ->and(User::query()->where('email', 'directora@nueva.example')->exists())->toBeFalse();
});

it('CA-016-13: suspending an organization with a reason changes its status and audits who, when, why and the states', function (): void {
    $platform = platformSession();
    $organization = Memberships::organization();

    Livewire::test(ViewOrganization::class, ['record' => $organization->getRouteKey()])
        ->callAction('suspend', ['reason' => 'Falta de pago del servicio durante tres meses'])
        ->assertHasNoFormErrors();

    $entry = Activity::query()->where('event', 'organization_suspended')->sole();

    expect($organization->fresh()->status)->toBe(OrganizationStatus::Suspended)
        ->and($entry->causer_id)->toBe($platform->id)
        ->and($entry->organization_id)->toBe($organization->id)
        ->and($entry->properties['reason'])->toBe('Falta de pago del servicio durante tres meses')
        ->and($entry->properties['old']['status'])->toBe('active')
        ->and($entry->properties['new']['status'])->toBe('suspended');
});

it('CA-016-14: a missing or short reason is refused and nothing changes', function (string $reason): void {
    platformSession();
    $organization = Memberships::organization();

    Livewire::test(ViewOrganization::class, ['record' => $organization->getRouteKey()])
        ->callAction('suspend', ['reason' => $reason])
        ->assertHasFormErrors(['reason']);

    expect($organization->fresh()->status)->toBe(OrganizationStatus::Active)
        ->and(Activity::query()->where('event', 'organization_suspended')->count())->toBe(0);
})->with(['empty' => [''], 'too short' => ['corto']]);

it('CA-016-14: suspending an organization that is already suspended changes nothing and does not duplicate the record', function (): void {
    $platform = platformSession();
    $organization = Memberships::organization();

    app(SuspendOrganization::class)->handle($platform, $organization, ['reason' => 'Motivo suficientemente largo']);
    app(SuspendOrganization::class)->handle($platform, $organization->fresh(), ['reason' => 'Otro motivo suficientemente largo']);

    expect(Activity::query()->where('event', 'organization_suspended')->count())->toBe(1);
});

it('CA-016-14: the reason is validated in the Action too, not only in the form', function (): void {
    $platform = User::factory()->platformAdmin()->create();
    $organization = Memberships::organization();

    expect(fn () => app(SuspendOrganization::class)->handle($platform, $organization, ['reason' => str_repeat('x', 501)]))
        ->toThrow(ValidationException::class);
});

it('CA-016-15: the members of a suspended organization lose the panel, even with an open session', function (): void {
    $platform = User::factory()->platformAdmin()->create();
    $organization = Memberships::organization();
    $member = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);

    $this->actingAs($member)->get("/admin/{$organization->slug}")->assertOk();
    expect($member->getTenants(Filament::getPanel('admin')))->toHaveCount(1);

    app(SuspendOrganization::class)->handle($platform, $organization, ['reason' => 'Suspensión por incumplimiento']);

    expect($this->actingAs($member->fresh())->get("/admin/{$organization->slug}")->status())->toBeIn([403, 404])
        ->and($member->fresh()->getTenants(Filament::getPanel('admin')))->toHaveCount(0)
        ->and($member->fresh()->canAccessPanel(Filament::getPanel('admin')))->toBeFalse()
        ->and(Organization::query()->whereKey($organization->id)->exists())->toBeTrue();
});

it('CA-016-16: reactivating an organization gives its members the access back and audits it', function (): void {
    $platform = User::factory()->platformAdmin()->create();
    $organization = Memberships::organization();
    $member = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    app(SuspendOrganization::class)->handle($platform, $organization, ['reason' => 'Suspensión por incumplimiento']);

    app(ReactivateOrganization::class)->handle($platform, $organization->fresh());

    $entry = Activity::query()->where('event', 'organization_reactivated')->sole();
    expect($organization->fresh()->status)->toBe(OrganizationStatus::Active)
        ->and($entry->properties['old']['status'])->toBe('suspended')
        ->and($entry->properties['new']['status'])->toBe('active');
    $this->actingAs($member->fresh())->get("/admin/{$organization->slug}")->assertOk();
});

it('CA-016-17: an org_admin, an organizer or a reviewer cannot suspend or reactivate an organization', function (OrganizationRole $role): void {
    $organization = Memberships::organization();
    $user = Memberships::attach(User::factory()->create(), $organization, $role);

    expect(fn () => app(SuspendOrganization::class)->handle($user, $organization, ['reason' => 'Un motivo suficientemente largo']))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(ReactivateOrganization::class)->handle($user, $organization))
        ->toThrow(AuthorizationException::class);

    expect($organization->fresh()->status)->toBe(OrganizationStatus::Active);
})->with([OrganizationRole::OrgAdmin, OrganizationRole::Organizer, OrganizationRole::Reviewer]);

it('CA-016-17: the list of organizations is forbidden to a member of an organization', function (): void {
    $user = Memberships::attach(User::factory()->create(), Memberships::organization(), OrganizationRole::OrgAdmin);
    $this->actingAs($user);

    Livewire::test(ListOrganizations::class)->assertForbidden();
});

it('CA-016-18: no screen of the platform panel offers to delete an organization', function (): void {
    platformSession();
    $organization = Memberships::organization();

    Livewire::test(ListOrganizations::class)
        ->assertTableActionDoesNotExist('delete')
        ->assertActionDoesNotExist('delete');
    Livewire::test(ViewOrganization::class, ['record' => $organization->getRouteKey()])
        ->assertActionDoesNotExist('delete');
});
