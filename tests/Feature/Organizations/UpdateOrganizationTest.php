<?php

declare(strict_types=1);

use App\Actions\Organizations\UpdateOrganization;
use App\Enums\OrganizationRole;
use App\Filament\Pages\Organization\EditOrganizationProfile;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

function actInProfilePanel(User $user, Organization $organization): void
{
    Auth::shouldUse('web');
    Auth::guard('web')->setUser($user);
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);
}

it('CA-028-1: an administrator renames the organization, the slug stays and the change is audited', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $slug = $organization->slug;
    $oldName = $organization->name;

    $updated = app(UpdateOrganization::class)->handle($admin, $organization, ['name' => 'Nuevo nombre']);

    expect($updated->name)->toBe('Nuevo nombre')
        ->and($updated->fresh()->slug)->toBe($slug);

    $log = Activity::query()->where('event', 'organization_updated')->sole();
    expect($log->causer_id)->toBe($admin->id)
        ->and($log->properties['old']['name'])->toBe($oldName)
        ->and($log->properties['new']['name'])->toBe('Nuevo nombre');
});

it('CA-028-2: an empty or too long name is rejected and nothing changes', function (string $name): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $oldName = $organization->name;

    expect(fn () => app(UpdateOrganization::class)->handle($admin, $organization, ['name' => $name]))
        ->toThrow(ValidationException::class);

    expect($organization->fresh()->name)->toBe($oldName);
})->with(['empty' => '', 'too long' => fn () => str_repeat('a', 121)]);

it('CA-028-3: an organizer or a reviewer cannot rename the organization', function (OrganizationRole $role): void {
    $organization = Memberships::organization();
    $member = Memberships::attach(User::factory()->create(), $organization, $role);
    $oldName = $organization->name;

    expect(fn () => app(UpdateOrganization::class)->handle($member, $organization, ['name' => 'Otro']))
        ->toThrow(AuthorizationException::class);

    actInProfilePanel($member, $organization);
    expect(EditOrganizationProfile::canView($organization))->toBeFalse()
        ->and($organization->fresh()->name)->toBe($oldName);
})->with([OrganizationRole::Organizer, OrganizationRole::Reviewer]);

it('CA-028-4: an administrator of A cannot edit organization B', function (): void {
    $a = Memberships::organization();
    $b = Memberships::organization();
    $adminOfA = Memberships::attach(User::factory()->create(), $a, OrganizationRole::OrgAdmin);
    $oldName = $b->name;

    expect(fn () => app(UpdateOrganization::class)->handle($adminOfA, $b, ['name' => 'Intruso']))
        ->toThrow(AuthorizationException::class);

    expect($b->fresh()->name)->toBe($oldName);
});

it('CA-028-5: the page shows an editable name and a read-only identifier, and saves', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    actInProfilePanel($admin, $organization);

    Livewire::test(EditOrganizationProfile::class)
        ->assertFormSet(['name' => $organization->name, 'slug' => $organization->slug])
        ->assertFormFieldDisabled('slug')
        ->fillForm(['name' => 'Desde la página'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($organization->fresh()->name)->toBe('Desde la página');
});
