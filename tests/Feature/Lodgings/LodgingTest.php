<?php

declare(strict_types=1);

use App\Actions\Lodgings\CreateLodging;
use App\Actions\Lodgings\DeleteLodging;
use App\Actions\Lodgings\UpdateLodging;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\ManageEventLodgings;
use App\Models\Event;
use App\Models\Lodging;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\RolePermissions;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/form-helpers.php';

/*
 * Plan 030: «Hospedaje» como sección del evento. Cada prueba usa un solo rol: la caché de permisos de
 * Spatie se comparte dentro del proceso.
 */

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/** @return Testable<ManageEventLodgings> */
function lodgingsPage(Organization $organization, User $user, Event $event): Testable
{
    Auth::login($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    return Livewire::test(ManageEventLodgings::class, ['record' => $event->id]);
}

/** @param  array<string, mixed>  $data */
function newLodging(User $actor, Event $event, array $data = []): Lodging
{
    return app(CreateLodging::class)->handle($actor, $event, ['name' => 'Hotel Playa', ...$data]);
}

it('CA-030-1: an admin adds a lodging with only a name, it goes to the end of the list and it is audited', function (): void {
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);

    $first = newLodging($admin, $event);
    $second = newLodging($admin, $event, ['name' => 'Hotel Centro', 'address' => ' Av. del Mar 1 ', 'rate' => 'CONGRESO27']);

    expect($first->position)->toBe(1)
        ->and($second->position)->toBe(2)
        ->and($second->address)->toBe('Av. del Mar 1')
        ->and($second->description)->toBeNull()
        ->and($second->event_id)->toBe($event->id);

    $log = Activity::query()->where('event', 'lodging_created')->where('subject_id', $second->id)->firstOrFail();

    expect($log->properties['lodging_id'])->toBe($second->id)
        ->and($log->properties->has('name'))->toBeFalse();
});

it('CA-030-2: an empty name or a booking link that is not http or https is refused and nothing is saved', function (array $data, string $field): void {
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);

    expect(validationErrorKeys(fn () => app(CreateLodging::class)->handle($admin, $event, $data)))->toBe([$field])
        ->and(Lodging::query()->count())->toBe(0);
})->with([
    'empty name' => [['name' => '  '], 'name'],
    'javascript link' => [['name' => 'Hotel', 'booking_url' => 'javascript:alert(1)'], 'booking_url'],
    'ftp link' => [['name' => 'Hotel', 'booking_url' => 'ftp://hotel.test/reserva'], 'booking_url'],
    'negative order' => [['name' => 'Hotel', 'position' => -1], 'position'],
]);

it('CA-030-3: editing changes only the given fields, audits their names and leaves the other lodgings alone', function (): void {
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);
    $lodging = newLodging($admin, $event, ['address' => 'Calle 1', 'booking_url' => 'https://hotel.test/reserva']);
    $other = newLodging($admin, $event, ['name' => 'Otro']);

    $updated = app(UpdateLodging::class)->handle($admin, $lodging, ['rate' => '10% de descuento', 'address' => '']);

    expect($updated->rate)->toBe('10% de descuento')
        ->and($updated->address)->toBeNull()
        ->and($updated->name)->toBe('Hotel Playa')
        ->and($updated->booking_url)->toBe('https://hotel.test/reserva')
        ->and(Lodging::query()->findOrFail($other->id)->name)->toBe('Otro');

    $log = Activity::query()->where('event', 'lodging_updated')->latest()->firstOrFail();

    expect($log->properties['changed'])->toContain('rate', 'address')
        ->and($log->properties['changed'])->not->toContain('name');
});

it('CA-030-3: editing cannot leave the name empty', function (): void {
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);
    $lodging = newLodging($admin, $event);

    expect(fn () => app(UpdateLodging::class)->handle($admin, $lodging, ['name' => '']))->toThrow(ValidationException::class)
        ->and(Lodging::query()->findOrFail($lodging->id)->name)->toBe('Hotel Playa');
});

it('CA-030-3: deleting removes the lodging and audits it, keeping the others', function (): void {
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);
    $lodging = newLodging($admin, $event);
    $other = newLodging($admin, $event, ['name' => 'Otro']);

    app(DeleteLodging::class)->handle($admin, $lodging);

    expect(Lodging::query()->pluck('id')->all())->toBe([$other->id])
        ->and(Activity::query()->where('event', 'lodging_deleted')->count())->toBe(1);
});

it('CA-030-7: an organizer adds and edits but cannot delete; a reviewer can do nothing', function (): void {
    [$organization, $event, $organizer, $admin] = formScenario(OrganizationRole::Organizer);
    CurrentOrganization::set($organization);
    $lodging = newLodging($organizer, $event);

    app(UpdateLodging::class)->handle($organizer, $lodging, ['name' => 'Renombrado']);

    expect(fn () => app(DeleteLodging::class)->handle($organizer, $lodging))->toThrow(AuthorizationException::class)
        ->and(Lodging::query()->count())->toBe(1);

    [, $reviewerEvent, $reviewer] = formScenario(OrganizationRole::Reviewer);

    expect(fn () => app(CreateLodging::class)->handle($reviewer, $reviewerEvent, ['name' => 'X']))->toThrow(AuthorizationException::class);
});

it('CA-030-8: an admin of another organization cannot create, edit or delete the lodgings of this one', function (): void {
    [$organizationA, $eventA, $adminA] = formScenario();
    [, , $adminB] = formScenario();
    CurrentOrganization::set($organizationA);
    $lodging = newLodging($adminA, $eventA);

    expect(fn () => app(CreateLodging::class)->handle($adminB, $eventA, ['name' => 'X']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateLodging::class)->handle($adminB, $lodging, ['name' => 'X']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteLodging::class)->handle($adminB, $lodging))->toThrow(AuthorizationException::class)
        ->and(Lodging::query()->findOrFail($lodging->id)->name)->toBe('Hotel Playa');
});

it('CA-030-1: in the panel the empty list shows its empty state and the admin adds a lodging', function (): void {
    [$organization, $event, $admin] = formScenario();

    $component = lodgingsPage($organization, $admin, $event)->assertSee(__('lodgings.empty_heading'));

    $component->callAction('createLodging', ['name' => 'Hotel Playa', 'booking_url' => 'https://hotel.test'])
        ->assertHasNoActionErrors();

    $lodging = Lodging::query()->where('event_id', $event->id)->firstOrFail();

    $component->assertCanSeeTableRecords([$lodging]);

    expect($lodging->name)->toBe('Hotel Playa');
});

it('CA-030-2: in the panel a bad link shows the error next to the field and saves nothing', function (): void {
    [$organization, $event, $admin] = formScenario();

    lodgingsPage($organization, $admin, $event)
        ->callAction('createLodging', ['name' => 'Hotel', 'booking_url' => 'javascript:alert(1)'])
        ->assertHasActionErrors(['booking_url']);

    expect(Lodging::query()->count())->toBe(0);
});

it('CA-030-3: in the panel the admin edits and deletes a lodging', function (): void {
    [$organization, $event, $admin] = formScenario();
    $lodging = newLodging($admin, $event);

    $component = lodgingsPage($organization, $admin, $event)
        ->callAction(TestAction::make('editLodging')->table($lodging), ['name' => 'Hotel Nuevo', 'rate' => 'ABC'])
        ->assertHasNoActionErrors();

    expect(Lodging::query()->findOrFail($lodging->id)->name)->toBe('Hotel Nuevo');

    $component->callAction(TestAction::make('deleteLodging')->table($lodging))->assertHasNoActionErrors();

    expect(Lodging::query()->count())->toBe(0);
});

it('CA-030-7: in the panel an organizer does not see the delete button', function (): void {
    [$organization, $event, $organizer, $admin] = formScenario(OrganizationRole::Organizer);
    $lodging = newLodging($admin, $event);

    lodgingsPage($organization, $organizer, $event)
        ->assertActionVisible('createLodging')
        ->assertActionVisible(TestAction::make('editLodging')->table($lodging))
        ->assertActionHidden(TestAction::make('deleteLodging')->table($lodging));
});

it('CA-030-7: a reviewer has no access to the page, by class or by URL', function (): void {
    [$organization, $event, $reviewer] = formScenario(OrganizationRole::Reviewer);
    Auth::login($reviewer);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    expect(ManageEventLodgings::canAccess(['record' => $event]))->toBeFalse()
        ->and($this->actingAs($reviewer)->get(EventResource::getUrl('lodgings', ['record' => $event], panel: 'admin', tenant: $organization))->status())->toBeIn([403, 404]);
});

it('CA-030-9: the admin reaches the page by URL and the event menu links to it', function (): void {
    [$organization, $event, $admin] = formScenario();
    $url = EventResource::getUrl('lodgings', ['record' => $event], panel: 'admin', tenant: $organization);

    $this->actingAs($admin)->get($url)->assertOk()->assertSee($url, false);
    $this->actingAs($admin)->get(EventResource::getUrl('view', ['record' => $event], panel: 'admin', tenant: $organization))
        ->assertOk()
        ->assertSee(__('lodgings.navigation'));
});

it('CA-030-8: the lodgings of an event of another organization answer 404 and the list never mixes events', function (): void {
    [$organizationA, $eventA, $adminA] = formScenario();
    [, $eventB, $adminB] = formScenario();
    $own = newLodging($adminA, $eventA);
    $foreign = newLodging($adminB, $eventB, ['name' => 'Ajeno']);

    $this->actingAs($adminA)->get(EventResource::getUrl('lodgings', ['record' => $eventB], panel: 'admin', tenant: $organizationA))->assertNotFound();

    lodgingsPage($organizationA, $adminA, $eventA)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$foreign]);
});

it('CA-030-7: the policy lets org_admin and organizer manage, only org_admin delete, and a reviewer nothing', function (): void {
    foreach ([[OrganizationRole::OrgAdmin, true, true], [OrganizationRole::Organizer, true, false], [OrganizationRole::Reviewer, false, false]] as [$role, $manages, $deletes]) {
        [$organization, $event, $user, $admin] = formScenario($role);
        $lodging = newLodging($admin, $event);

        $allows = fn (string $ability, mixed $arguments): bool => CurrentOrganization::run(
            $organization,
            fn (): bool => Gate::forUser($user->unsetRelation('roles')->unsetRelation('permissions'))->allows($ability, $arguments),
        );

        expect($allows('viewAny', Lodging::class))->toBe($manages)
            ->and($allows('view', $lodging))->toBe($manages)
            ->and($allows('create', [Lodging::class, $event]))->toBe($manages)
            ->and($allows('update', $lodging))->toBe($manages)
            ->and($allows('delete', $lodging))->toBe($deletes);
    }
});

it('CA-030-10: the roles matrix gives lodgings.manage and sponsors.manage to org_admin and organizer, and the .delete ones only to org_admin', function (): void {
    $matrix = RolePermissions::matrix();

    foreach (['lodgings.manage', 'sponsors.manage'] as $permission) {
        expect($matrix['org_admin'])->toContain($permission)
            ->and($matrix['organizer'])->toContain($permission)
            ->and($matrix['reviewer'])->not->toContain($permission);
    }

    foreach (['lodgings.delete', 'sponsors.delete'] as $permission) {
        expect($matrix['org_admin'])->toContain($permission)
            ->and($matrix['organizer'])->not->toContain($permission)
            ->and($matrix['reviewer'])->not->toContain($permission);
    }
});
