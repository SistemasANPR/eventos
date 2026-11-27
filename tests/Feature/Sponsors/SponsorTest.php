<?php

declare(strict_types=1);

use App\Actions\Sponsors\CreateSponsor;
use App\Actions\Sponsors\CreateSponsorCategory;
use App\Actions\Sponsors\DeleteSponsor;
use App\Actions\Sponsors\UpdateSponsor;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\ManageEventSponsors;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Sponsor;
use App\Models\User;
use App\Support\Files\MediaDisk;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/form-helpers.php';

/*
 * Plan 030: «Patrocinadores» como sección del evento. Cada prueba usa un solo rol: la caché de permisos de
 * Spatie se comparte dentro del proceso.
 */

beforeEach(function (): void {
    Storage::fake(MediaDisk::name());
});

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/** @return Testable<ManageEventSponsors> */
function sponsorsPage(Organization $organization, User $user, Event $event): Testable
{
    Auth::login($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    return Livewire::test(ManageEventSponsors::class, ['record' => $event->id]);
}

/** @param  array<string, mixed>  $data */
function newSponsor(User $actor, Event $event, array $data = []): Sponsor
{
    return app(CreateSponsor::class)->handle($actor, $event, ['name' => 'Acme', ...$data]);
}

it('CA-030-4: an admin adds a sponsor with name, level, website and logo; the logo goes to the media disk of the event', function (): void {
    [$organization, $event, $admin] = formScenario();
    $gold = app(CreateSponsorCategory::class)->handle($admin, $event, ['name' => 'Oro']);

    $sponsor = newSponsor($admin, $event, [
        'sponsor_category_id' => $gold->id,
        'website_url' => 'https://acme.test',
        'logo' => UploadedFile::fake()->image('logo.png', 40, 40),
    ]);

    expect($sponsor->sponsor_category_id)->toBe($gold->id)
        ->and($sponsor->position)->toBe(1)
        ->and((string) $sponsor->logo_path)->toStartWith("organizations/{$organization->id}/events/{$event->id}/media/sponsor_logo/")
        ->and(Storage::disk(MediaDisk::name())->exists((string) $sponsor->logo_path))->toBeTrue()
        ->and(Storage::disk(MediaDisk::name())->getVisibility((string) $sponsor->logo_path))->toBe('private')
        ->and(newSponsor($admin, $event, ['name' => 'Beta'])->position)->toBe(2);

    $log = Activity::query()->where('event', 'sponsor_created')->where('subject_id', $sponsor->id)->firstOrFail();

    expect($log->properties['sponsor_id'])->toBe($sponsor->id);
});

it('CA-030-5: a bad website, an empty name or a fake image is refused and no file is left behind', function (): void {
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);

    foreach ([
        ['name' => 'Acme', 'website_url' => 'javascript:alert(1)'],
        ['name' => ' '],
        ['name' => 'Acme', 'logo' => UploadedFile::fake()->createWithContent('a.png', 'esto no es una imagen')],
    ] as $data) {
        expect(fn () => app(CreateSponsor::class)->handle($admin, $event, $data))->toThrow(ValidationException::class);
    }

    expect(Sponsor::query()->count())->toBe(0)
        ->and(Storage::disk(MediaDisk::name())->allFiles())->toBe([]);
});

it('CA-030-5: the error of a fake logo is reported in the logo field', function (): void {
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);

    expect(validationErrorKeys(fn () => app(CreateSponsor::class)->handle($admin, $event, [
        'name' => 'Acme',
        'logo' => UploadedFile::fake()->createWithContent('a.png', 'esto no es una imagen'),
    ])))->toBe(['logo']);
});

it('CA-030-6: replacing the logo deletes the old file, a failed edit keeps it, and removing it deletes it too', function (): void {
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);
    $sponsor = newSponsor($admin, $event, ['logo' => UploadedFile::fake()->image('uno.png', 30, 30)]);
    $disk = Storage::disk(MediaDisk::name());
    $first = (string) $sponsor->logo_path;

    expect(fn () => app(UpdateSponsor::class)->handle($admin, $sponsor, ['website_url' => 'ftp://x.test', 'logo' => UploadedFile::fake()->image('dos.png', 30, 30)]))
        ->toThrow(ValidationException::class);

    expect($disk->exists($first))->toBeTrue()
        ->and($disk->allFiles())->toHaveCount(1);

    $replaced = app(UpdateSponsor::class)->handle($admin, $sponsor, ['logo' => UploadedFile::fake()->image('tres.png', 30, 30)]);
    $second = (string) $replaced->logo_path;

    expect($second)->not->toBe($first)
        ->and($disk->exists($first))->toBeFalse()
        ->and($disk->exists($second))->toBeTrue();

    $removed = app(UpdateSponsor::class)->handle($admin, $replaced, ['remove_logo' => true]);

    expect($removed->logo_path)->toBeNull()
        ->and($disk->allFiles())->toBe([]);

    $changes = Activity::query()->where('event', 'sponsor_updated')->get()
        ->flatMap(fn (Activity $log): array => $log->properties['changed'])
        ->all();

    expect($changes)->toContain('logo');
});

it('CA-030-6: editing keeps the logo when no new one comes, and deleting the sponsor deletes its file', function (): void {
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);
    $sponsor = newSponsor($admin, $event, ['logo' => UploadedFile::fake()->image('uno.png', 30, 30)]);
    $other = newSponsor($admin, $event, ['name' => 'Otro']);
    $path = (string) $sponsor->logo_path;
    $silver = app(CreateSponsorCategory::class)->handle($admin, $event, ['name' => 'Plata']);

    $updated = app(UpdateSponsor::class)->handle($admin, $sponsor, ['sponsor_category_id' => $silver->id, 'website_url' => '']);

    expect($updated->logo_path)->toBe($path)
        ->and($updated->sponsor_category_id)->toBe($silver->id)
        ->and($updated->website_url)->toBeNull();

    app(DeleteSponsor::class)->handle($admin, $updated);

    expect(Sponsor::query()->pluck('id')->all())->toBe([$other->id])
        ->and(Storage::disk(MediaDisk::name())->exists($path))->toBeFalse()
        ->and(Activity::query()->where('event', 'sponsor_deleted')->count())->toBe(1);
});

it('CA-030-7: an organizer adds and edits but cannot delete; a reviewer can do nothing', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    CurrentOrganization::set($organization);
    $sponsor = newSponsor($organizer, $event);

    app(UpdateSponsor::class)->handle($organizer, $sponsor, ['name' => 'Renombrado']);

    expect(fn () => app(DeleteSponsor::class)->handle($organizer, $sponsor))->toThrow(AuthorizationException::class)
        ->and(Sponsor::query()->count())->toBe(1);

    [, $reviewerEvent, $reviewer] = formScenario(OrganizationRole::Reviewer);

    expect(fn () => app(CreateSponsor::class)->handle($reviewer, $reviewerEvent, ['name' => 'X']))->toThrow(AuthorizationException::class);
});

it('CA-030-8: an admin of another organization cannot create, edit or delete the sponsors of this one', function (): void {
    [$organizationA, $eventA, $adminA] = formScenario();
    [, , $adminB] = formScenario();
    CurrentOrganization::set($organizationA);
    $sponsor = newSponsor($adminA, $eventA);

    expect(fn () => app(CreateSponsor::class)->handle($adminB, $eventA, ['name' => 'X']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateSponsor::class)->handle($adminB, $sponsor, ['name' => 'X']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteSponsor::class)->handle($adminB, $sponsor))->toThrow(AuthorizationException::class)
        ->and(Sponsor::query()->findOrFail($sponsor->id)->name)->toBe('Acme');
});

it('CA-030-4: in the panel the empty list shows its empty state, the admin adds a sponsor with a logo and the list shows its thumbnail', function (): void {
    [$organization, $event, $admin] = formScenario();

    $gold = app(CreateSponsorCategory::class)->handle($admin, $event, ['name' => 'Oro']);
    $component = sponsorsPage($organization, $admin, $event)->assertSee(__('sponsors.empty_heading'));

    $component->callAction('createSponsor', [
        'name' => 'Acme',
        'sponsor_category_id' => $gold->id,
        'website_url' => 'https://acme.test',
        'logo' => UploadedFile::fake()->image('logo.png', 30, 30),
    ])->assertHasNoActionErrors();

    $sponsor = Sponsor::query()->where('event_id', $event->id)->firstOrFail();

    $component->assertCanSeeTableRecords([$sponsor]);

    expect($sponsor->sponsor_category_id)->toBe($gold->id)
        ->and($sponsor->logo_path)->not->toBeNull();
});

it('CA-030-5: in the panel a bad website shows the error next to the field and saves nothing', function (): void {
    [$organization, $event, $admin] = formScenario();

    sponsorsPage($organization, $admin, $event)
        ->callAction('createSponsor', ['name' => 'Acme', 'website_url' => 'javascript:alert(1)'])
        ->assertHasActionErrors(['website_url']);

    expect(Sponsor::query()->count())->toBe(0);
});

it('CA-030-6: in the panel the admin edits, removes the logo and deletes a sponsor', function (): void {
    [$organization, $event, $admin] = formScenario();
    $sponsor = newSponsor($admin, $event, ['logo' => UploadedFile::fake()->image('uno.png', 30, 30)]);

    $component = sponsorsPage($organization, $admin, $event)
        ->callAction(TestAction::make('editSponsor')->table($sponsor), ['name' => 'Acme 2', 'remove_logo' => true])
        ->assertHasNoActionErrors();

    $fresh = Sponsor::query()->findOrFail($sponsor->id);

    expect($fresh->name)->toBe('Acme 2')
        ->and($fresh->logo_path)->toBeNull();

    $component->callAction(TestAction::make('deleteSponsor')->table($sponsor))->assertHasNoActionErrors();

    expect(Sponsor::query()->count())->toBe(0);
});

it('CA-030-7: in the panel an organizer does not see the delete button', function (): void {
    [$organization, $event, $organizer, $admin] = formScenario(OrganizationRole::Organizer);
    $sponsor = newSponsor($admin, $event);

    sponsorsPage($organization, $organizer, $event)
        ->assertActionVisible('createSponsor')
        ->assertActionVisible(TestAction::make('editSponsor')->table($sponsor))
        ->assertActionHidden(TestAction::make('deleteSponsor')->table($sponsor));
});

it('CA-030-7: a reviewer has no access to the page, by class or by URL', function (): void {
    [$organization, $event, $reviewer] = formScenario(OrganizationRole::Reviewer);
    Auth::login($reviewer);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    expect(ManageEventSponsors::canAccess(['record' => $event]))->toBeFalse()
        ->and($this->actingAs($reviewer)->get(EventResource::getUrl('sponsors', ['record' => $event], panel: 'admin', tenant: $organization))->status())->toBeIn([403, 404]);
});

it('CA-030-9: the admin reaches the page by URL and the event menu links to it', function (): void {
    [$organization, $event, $admin] = formScenario();
    $url = EventResource::getUrl('sponsors', ['record' => $event], panel: 'admin', tenant: $organization);

    $this->actingAs($admin)->get($url)->assertOk()->assertSee($url, false);
    $this->actingAs($admin)->get(EventResource::getUrl('view', ['record' => $event], panel: 'admin', tenant: $organization))
        ->assertOk()
        ->assertSee(__('sponsors.navigation'));
});

it('CA-030-8: the sponsors of an event of another organization answer 404 and the list never mixes events', function (): void {
    [$organizationA, $eventA, $adminA] = formScenario();
    [, $eventB, $adminB] = formScenario();
    $own = newSponsor($adminA, $eventA);
    $foreign = newSponsor($adminB, $eventB, ['name' => 'Ajeno']);

    $this->actingAs($adminA)->get(EventResource::getUrl('sponsors', ['record' => $eventB], panel: 'admin', tenant: $organizationA))->assertNotFound();

    sponsorsPage($organizationA, $adminA, $eventA)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$foreign]);
});

it('CA-030-7: the policy lets org_admin and organizer manage, only org_admin delete, and a reviewer nothing', function (): void {
    foreach ([[OrganizationRole::OrgAdmin, true, true], [OrganizationRole::Organizer, true, false], [OrganizationRole::Reviewer, false, false]] as [$role, $manages, $deletes]) {
        [$organization, $event, $user, $admin] = formScenario($role);
        $sponsor = newSponsor($admin, $event);

        $allows = fn (string $ability, mixed $arguments): bool => CurrentOrganization::run(
            $organization,
            fn (): bool => Gate::forUser($user->unsetRelation('roles')->unsetRelation('permissions'))->allows($ability, $arguments),
        );

        expect($allows('viewAny', Sponsor::class))->toBe($manages)
            ->and($allows('view', $sponsor))->toBe($manages)
            ->and($allows('create', [Sponsor::class, $event]))->toBe($manages)
            ->and($allows('update', $sponsor))->toBe($manages)
            ->and($allows('delete', $sponsor))->toBe($deletes);
    }
});
