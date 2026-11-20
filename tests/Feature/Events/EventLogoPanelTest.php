<?php

declare(strict_types=1);

use App\Actions\Events\CreateEvent as CreateEventAction;
use App\Actions\Events\StoreEventLogo;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Support\Files\MediaDisk;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

/* Plan 027, F2: campo «Logotipo» en crear y editar el evento desde el panel. */

beforeEach(function (): void {
    Storage::fake(MediaDisk::name());
});

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

function logoPanel(User $user, Organization $organization): void
{
    Auth::login($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function logoPanelData(array $overrides = []): array
{
    return array_merge([
        'name' => ['es' => 'Congreso Parques 2027'],
        'slug' => 'congreso-2027',
        'type' => 'congress',
        'starts_at' => '2027-03-10 09:00',
        'ends_at' => '2027-03-12 17:00',
        'timezone' => 'America/Mexico_City',
        'locales' => ['es'],
        'default_locale' => 'es',
        'currency' => 'MXN',
    ], $overrides);
}

/** @return array{0: Organization, 1: User} */
function logoPanelSetup(OrganizationRole $role = OrganizationRole::OrgAdmin): array
{
    $organization = Memberships::organization();

    return [$organization, Memberships::attach(User::factory()->create(), $organization, $role)];
}

/** @return list<string> */
function panelLogoFiles(): array
{
    return Storage::disk(MediaDisk::name())->allFiles();
}

it('CA-027-14: the create form shows the Logo field and saves the logo through the Action', function (): void {
    [$organization, $admin] = logoPanelSetup();
    logoPanel($admin, $organization);

    Livewire::test(CreateEvent::class)
        ->assertFormFieldExists('logo')
        ->assertFormFieldHidden('remove_logo')
        ->fillForm(logoPanelData(['logo' => UploadedFile::fake()->image('logo.png', 30, 30)]))
        ->call('create')
        ->assertHasNoFormErrors();

    $event = Event::query()->sole();

    expect($event->logo_path)->toStartWith("organizations/{$organization->id}/events/{$event->id}/media/event_logo/")
        ->and(panelLogoFiles())->toBe([$event->logo_path]);
});

it('CA-027-14: the create form without a logo works as before', function (): void {
    [$organization, $admin] = logoPanelSetup();
    logoPanel($admin, $organization);

    Livewire::test(CreateEvent::class)->fillForm(logoPanelData())->call('create')->assertHasNoFormErrors();

    expect(Event::query()->sole()->logo_path)->toBeNull()->and(panelLogoFiles())->toBe([]);
});

it('CA-027-3: a fake image is rejected in the Logo field and nothing is saved', function (): void {
    [$organization, $admin] = logoPanelSetup();
    logoPanel($admin, $organization);

    Livewire::test(CreateEvent::class)
        ->fillForm(logoPanelData(['logo' => UploadedFile::fake()->createWithContent('logo.png', '<svg xmlns="http://www.w3.org/2000/svg"/>')]))
        ->call('create')
        ->assertHasFormErrors(['logo']);

    expect(Event::query()->count())->toBe(0)->and(panelLogoFiles())->toBe([]);
});

it('CA-027-14: the edit form shows the preview and the remove switch only when the event has a logo', function (): void {
    [$organization, $admin] = logoPanelSetup();
    $event = app(CreateEventAction::class)->handle($admin, $organization, logoPanelData());
    logoPanel($admin, $organization);

    Livewire::test(EditEvent::class, ['record' => $event->id])
        ->assertFormFieldExists('logo')
        ->assertFormFieldHidden('remove_logo');

    app(StoreEventLogo::class)->handle($admin, $event, UploadedFile::fake()->image('a.png', 30, 30));

    Livewire::test(EditEvent::class, ['record' => $event->id])
        ->assertFormFieldVisible('remove_logo')
        ->assertSee(route('event-logo.show', ['organization' => $organization->slug, 'event' => $event->id], false));
});

it('CA-027-14: the edit form changes the logo and removes the previous file', function (): void {
    [$organization, $admin] = logoPanelSetup();
    $event = app(CreateEventAction::class)->handle($admin, $organization, logoPanelData());
    $first = app(StoreEventLogo::class)->handle($admin, $event, UploadedFile::fake()->image('a.png', 30, 30))->logo_path;
    logoPanel($admin, $organization);

    Livewire::test(EditEvent::class, ['record' => $event->id])
        ->fillForm(['logo' => UploadedFile::fake()->image('b.png', 30, 30)])
        ->call('save')
        ->assertHasNoFormErrors();

    $second = $event->fresh()->logo_path;

    expect($second)->not->toBe($first)->and(panelLogoFiles())->toBe([$second]);
});

it('CA-027-14: the edit form removes the logo with the switch and deletes the file', function (): void {
    [$organization, $admin] = logoPanelSetup();
    $event = app(CreateEventAction::class)->handle($admin, $organization, logoPanelData());
    app(StoreEventLogo::class)->handle($admin, $event, UploadedFile::fake()->image('a.png', 30, 30));
    logoPanel($admin, $organization);

    Livewire::test(EditEvent::class, ['record' => $event->id])
        ->fillForm(['remove_logo' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($event->fresh()->logo_path)->toBeNull()->and(panelLogoFiles())->toBe([]);
});

it('CA-027-8: saving the edit form without a new logo keeps the current one', function (): void {
    [$organization, $admin] = logoPanelSetup();
    $event = app(CreateEventAction::class)->handle($admin, $organization, logoPanelData());
    $path = app(StoreEventLogo::class)->handle($admin, $event, UploadedFile::fake()->image('a.png', 30, 30))->logo_path;
    logoPanel($admin, $organization);

    Livewire::test(EditEvent::class, ['record' => $event->id])
        ->fillForm(['city' => 'Puebla'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($event->fresh()->logo_path)->toBe($path)->and(panelLogoFiles())->toBe([$path]);
});

it('CA-027-9: an event of another organization cannot be edited or its logo previewed from this one', function (): void {
    [$organizationB, $adminB] = logoPanelSetup();
    $eventOfB = app(CreateEventAction::class)->handle($adminB, $organizationB, logoPanelData());
    app(StoreEventLogo::class)->handle($adminB, $eventOfB, UploadedFile::fake()->image('a.png', 30, 30));
    [$organizationA, $adminA] = logoPanelSetup();

    $this->actingAs($adminA)->get("/admin/{$organizationA->slug}/events/{$eventOfB->id}/edit")->assertNotFound();
    $this->actingAs($adminA)->get("/admin/{$organizationA->slug}/events/{$eventOfB->id}/logo")->assertNotFound();
});
