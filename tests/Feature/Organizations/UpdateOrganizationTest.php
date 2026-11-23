<?php

declare(strict_types=1);

use App\Actions\Organizations\UpdateOrganization;
use App\Enums\OrganizationRole;
use App\Filament\Pages\Organization\EditOrganizationProfile;
use App\Models\Event;
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

it('CA-028-5: the page shows an editable name and identifier with a warning about old URLs, and saves the name', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    actInProfilePanel($admin, $organization);

    Livewire::test(EditOrganizationProfile::class)
        ->assertFormSet(['name' => $organization->name, 'slug' => $organization->slug])
        ->assertFormFieldEnabled('slug')
        ->assertSee(__('organizations.profile.slug_warning'))
        ->fillForm(['name' => 'Desde la página'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($organization->fresh()->name)->toBe('Desde la página')
        ->and($organization->fresh()->slug)->toBe($organization->slug);
});

it('CA-028-6: an administrator changes the slug, it is audited and the panel redirects to the new URL', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $oldSlug = $organization->slug;
    actInProfilePanel($admin, $organization);

    Livewire::test(EditOrganizationProfile::class)
        ->fillForm(['slug' => 'nuevo-identificador'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertRedirect(url('/admin/nuevo-identificador/profile'));

    expect($organization->fresh()->slug)->toBe('nuevo-identificador');

    $log = Activity::query()->where('event', 'organization_updated')->sole();
    expect($log->properties['old']['slug'])->toBe($oldSlug)
        ->and($log->properties['new']['slug'])->toBe('nuevo-identificador');
});

it('CA-028-7: an invalid, reserved or taken slug is rejected and nothing changes', function (string $slug): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    Organization::factory()->create(['slug' => 'ocupado']);
    Organization::factory()->create(['slug' => 'eliminado'])->delete();
    $oldSlug = $organization->slug;

    expect(fn () => app(UpdateOrganization::class)->handle($admin, $organization, ['name' => $organization->name, 'slug' => $slug]))
        ->toThrow(ValidationException::class);

    expect($organization->fresh()->slug)->toBe($oldSlug);
})->with([
    'uppercase' => 'Mayusculas',
    'spaces' => 'con espacios',
    'too short' => 'ab',
    'reserved' => 'admin',
    'taken' => 'ocupado',
    'taken by a deleted one' => 'eliminado',
]);

it('CA-028-7: keeping its own slug is not a duplicate', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);

    $updated = app(UpdateOrganization::class)->handle($admin, $organization, ['name' => 'Mismo slug', 'slug' => $organization->slug]);

    expect($updated->slug)->toBe($organization->slug)->and($updated->name)->toBe('Mismo slug');
});

it('CA-028-8: after the slug changes, the old public URL answers 404 and the new one works', function (): void {
    $this->withoutVite();
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $event = Event::factory()->published()->create(['organization_id' => $organization->id, 'slug' => 'congreso']);
    $oldSlug = $organization->slug;
    $this->get("/{$oldSlug}/events/congreso")->assertOk();

    app(UpdateOrganization::class)->handle($admin, $organization, ['name' => $organization->name, 'slug' => 'identificador-nuevo']);

    $this->get("/{$oldSlug}/events/congreso")->assertNotFound();
    $this->get('/identificador-nuevo/events/congreso')->assertOk();
});
