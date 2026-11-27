<?php

declare(strict_types=1);

use App\Actions\Sponsors\CreateSponsor;
use App\Actions\Sponsors\CreateSponsorCategory;
use App\Actions\Sponsors\DeleteSponsorCategory;
use App\Actions\Sponsors\UpdateSponsor;
use App\Actions\Sponsors\UpdateSponsorCategory;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\ManageEventSponsorCategories;
use App\Filament\Resources\Events\Pages\ManageEventSponsors;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Sponsor;
use App\Models\SponsorCategory;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/form-helpers.php';

/*
 * Plan 030, F3: las categorías («Nivel») de patrocinador de un evento. Cada prueba usa un solo rol: la caché de
 * permisos de Spatie se comparte dentro del proceso.
 */

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/** @return Testable<ManageEventSponsorCategories> */
function sponsorCategoriesPage(Organization $organization, User $user, Event $event): Testable
{
    Auth::login($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    return Livewire::test(ManageEventSponsorCategories::class, ['record' => $event->id]);
}

it('CA-030-12: an admin adds categories to the end of the catalog, edits one, and both are audited', function (): void {
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);

    $gold = app(CreateSponsorCategory::class)->handle($admin, $event, ['name' => ' Oro ']);
    $silver = app(CreateSponsorCategory::class)->handle($admin, $event, ['name' => 'Plata']);

    expect($gold->name)->toBe('Oro')
        ->and($gold->position)->toBe(1)
        ->and($silver->position)->toBe(2)
        ->and($gold->event_id)->toBe($event->id);

    $renamed = app(UpdateSponsorCategory::class)->handle($admin, $gold, ['name' => 'Oro plus']);

    expect($renamed->name)->toBe('Oro plus');

    $created = Activity::query()->where('event', 'sponsor_category_created')->where('subject_id', $gold->id)->firstOrFail();
    $updated = Activity::query()->where('event', 'sponsor_category_updated')->where('subject_id', $gold->id)->firstOrFail();

    expect($created->properties['event_id'])->toBe($event->id)
        ->and($updated->properties['old']['name'])->toBe('Oro')
        ->and($updated->properties['new']['name'])->toBe('Oro plus');
});

it('CA-030-12: an empty name, a name over 100 characters or a repeated one (any case) is refused', function (): void {
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);
    $gold = app(CreateSponsorCategory::class)->handle($admin, $event, ['name' => 'Oro']);
    $silver = app(CreateSponsorCategory::class)->handle($admin, $event, ['name' => 'Plata']);

    foreach ([' ', str_repeat('a', 101), 'ORO', ' oro '] as $name) {
        expect(fn () => app(CreateSponsorCategory::class)->handle($admin, $event, ['name' => $name]))->toThrow(ValidationException::class);
    }

    // Una categoría no choca consigo misma, pero sí con otra.
    app(UpdateSponsorCategory::class)->handle($admin, $gold, ['name' => 'ORO']);

    expect(fn () => app(UpdateSponsorCategory::class)->handle($admin, $silver, ['name' => 'oro']))->toThrow(ValidationException::class)
        ->and(fn () => app(UpdateSponsorCategory::class)->handle($admin, $silver, []))->toThrow(ValidationException::class)
        ->and(SponsorCategory::query()->count())->toBe(2);
});

it('CA-030-12: the same name can exist in two different events', function (): void {
    [$organization, $eventA, $admin] = formScenario();
    CurrentOrganization::set($organization);
    $eventB = Event::factory()->create(['organization_id' => $organization->id]);

    app(CreateSponsorCategory::class)->handle($admin, $eventA, ['name' => 'Oro']);
    app(CreateSponsorCategory::class)->handle($admin, $eventB, ['name' => 'Oro']);

    expect(SponsorCategory::query()->where('name', 'Oro')->count())->toBe(2);
});

it('CA-030-11: a sponsor takes a category of its event, can leave it empty and changes it; a category of another event is refused', function (): void {
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);
    $otherEvent = Event::factory()->create(['organization_id' => $organization->id]);
    $gold = app(CreateSponsorCategory::class)->handle($admin, $event, ['name' => 'Oro']);
    $silver = app(CreateSponsorCategory::class)->handle($admin, $event, ['name' => 'Plata']);
    $foreign = app(CreateSponsorCategory::class)->handle($admin, $otherEvent, ['name' => 'Bronce']);

    $sponsor = app(CreateSponsor::class)->handle($admin, $event, ['name' => 'Acme', 'sponsor_category_id' => $gold->id]);
    $empty = app(CreateSponsor::class)->handle($admin, $event, ['name' => 'Sin nivel', 'sponsor_category_id' => '']);

    expect($sponsor->sponsor_category_id)->toBe($gold->id)
        ->and($empty->sponsor_category_id)->toBeNull();

    $moved = app(UpdateSponsor::class)->handle($admin, $sponsor, ['sponsor_category_id' => $silver->id]);

    expect($moved->sponsor_category_id)->toBe($silver->id);

    $cleared = app(UpdateSponsor::class)->handle($admin, $moved, ['sponsor_category_id' => null]);

    expect($cleared->sponsor_category_id)->toBeNull();

    foreach ([$foreign->id, 'no-es-un-uuid', (string) fake()->uuid()] as $invalid) {
        expect(validationErrorKeys(fn () => app(CreateSponsor::class)->handle($admin, $event, ['name' => 'X', 'sponsor_category_id' => $invalid])))->toBe(['sponsor_category_id'])
            ->and(validationErrorKeys(fn () => app(UpdateSponsor::class)->handle($admin, $sponsor, ['sponsor_category_id' => $invalid])))->toBe(['sponsor_category_id']);
    }

    expect(Sponsor::query()->count())->toBe(2);
});

it('CA-030-13: deleting a category leaves its sponsors without a level and keeps them', function (): void {
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);
    $gold = app(CreateSponsorCategory::class)->handle($admin, $event, ['name' => 'Oro']);
    $silver = app(CreateSponsorCategory::class)->handle($admin, $event, ['name' => 'Plata']);
    $a = app(CreateSponsor::class)->handle($admin, $event, ['name' => 'A', 'sponsor_category_id' => $gold->id]);
    $b = app(CreateSponsor::class)->handle($admin, $event, ['name' => 'B', 'sponsor_category_id' => $gold->id]);
    $c = app(CreateSponsor::class)->handle($admin, $event, ['name' => 'C', 'sponsor_category_id' => $silver->id]);

    app(DeleteSponsorCategory::class)->handle($admin, $gold);

    expect(SponsorCategory::query()->pluck('id')->all())->toBe([$silver->id])
        ->and(Sponsor::query()->count())->toBe(3)
        ->and($a->fresh()->sponsor_category_id)->toBeNull()
        ->and($b->fresh()->sponsor_category_id)->toBeNull()
        ->and($c->fresh()->sponsor_category_id)->toBe($silver->id);

    $log = Activity::query()->where('event', 'sponsor_category_deleted')->where('subject_id', $gold->id)->firstOrFail();

    expect($log->properties['sponsors_without_level'])->toBe(2)
        ->and($log->properties['old']['name'])->toBe('Oro');
});

it('CA-030-13: an organizer adds and edits categories but cannot delete one; a reviewer can do nothing', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    CurrentOrganization::set($organization);
    $category = app(CreateSponsorCategory::class)->handle($organizer, $event, ['name' => 'Oro']);

    app(UpdateSponsorCategory::class)->handle($organizer, $category, ['name' => 'Oro 2']);

    expect(fn () => app(DeleteSponsorCategory::class)->handle($organizer, $category))->toThrow(AuthorizationException::class)
        ->and(SponsorCategory::query()->count())->toBe(1);

    [, $reviewerEvent, $reviewer] = formScenario(OrganizationRole::Reviewer);

    expect(fn () => app(CreateSponsorCategory::class)->handle($reviewer, $reviewerEvent, ['name' => 'X']))->toThrow(AuthorizationException::class);
});

it('CA-030-12: the policy lets org_admin and organizer manage, only org_admin delete, and a reviewer nothing', function (): void {
    foreach ([[OrganizationRole::OrgAdmin, true, true], [OrganizationRole::Organizer, true, false], [OrganizationRole::Reviewer, false, false]] as [$role, $manages, $deletes]) {
        [$organization, $event, $user, $admin] = formScenario($role);
        $category = app(CreateSponsorCategory::class)->handle($admin, $event, ['name' => 'Oro']);

        $allows = fn (string $ability, mixed $arguments): bool => CurrentOrganization::run(
            $organization,
            fn (): bool => Gate::forUser($user->unsetRelation('roles')->unsetRelation('permissions'))->allows($ability, $arguments),
        );

        expect($allows('viewAny', SponsorCategory::class))->toBe($manages)
            ->and($allows('view', $category))->toBe($manages)
            ->and($allows('create', [SponsorCategory::class, $event]))->toBe($manages)
            ->and($allows('update', $category))->toBe($manages)
            ->and($allows('delete', $category))->toBe($deletes);
    }
});

it('CA-030-12: an admin of another organization cannot create, edit or delete the categories of this one', function (): void {
    [$organizationA, $eventA, $adminA] = formScenario();
    [, , $adminB] = formScenario();
    CurrentOrganization::set($organizationA);
    $category = app(CreateSponsorCategory::class)->handle($adminA, $eventA, ['name' => 'Oro']);

    expect(fn () => app(CreateSponsorCategory::class)->handle($adminB, $eventA, ['name' => 'X']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateSponsorCategory::class)->handle($adminB, $category, ['name' => 'X']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteSponsorCategory::class)->handle($adminB, $category))->toThrow(AuthorizationException::class)
        ->and(SponsorCategory::query()->findOrFail($category->id)->name)->toBe('Oro');
});

it('CA-030-17: in the panel the admin sees the empty state, adds, edits and deletes a category', function (): void {
    [$organization, $event, $admin] = formScenario();

    $component = sponsorCategoriesPage($organization, $admin, $event)->assertSee(__('sponsors.categories.empty_heading'));

    $component->callAction('createCategory', ['name' => 'Oro'])->assertHasNoActionErrors();

    $category = SponsorCategory::query()->where('event_id', $event->id)->firstOrFail();

    $component->assertCanSeeTableRecords([$category])
        ->callAction(TestAction::make('editCategory')->table($category), ['name' => 'Oro plus'])
        ->assertHasNoActionErrors();

    expect($category->fresh()->name)->toBe('Oro plus');

    // Un nombre repetido avisa y no crea nada.
    $component->callAction('createCategory', ['name' => 'ORO PLUS']);

    expect(SponsorCategory::query()->count())->toBe(1);

    $component->callAction(TestAction::make('deleteCategory')->table($category))->assertHasNoActionErrors();

    expect(SponsorCategory::query()->count())->toBe(0);
});

it('CA-030-17: in the panel an organizer cannot see the delete action of a category', function (): void {
    [$organization, $event, $organizer, $admin] = formScenario(OrganizationRole::Organizer);
    $category = app(CreateSponsorCategory::class)->handle($admin, $event, ['name' => 'Oro']);

    sponsorCategoriesPage($organization, $organizer, $event)
        ->assertActionVisible('createCategory')
        ->assertActionVisible(TestAction::make('editCategory')->table($category))
        ->assertActionHidden(TestAction::make('deleteCategory')->table($category));
});

it('CA-030-17: the sponsors page offers the categories button with its count, the level select lists the categories and the list shows and filters by level', function (): void {
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);
    $gold = app(CreateSponsorCategory::class)->handle($admin, $event, ['name' => 'Oro']);
    $silver = app(CreateSponsorCategory::class)->handle($admin, $event, ['name' => 'Plata']);
    $withGold = app(CreateSponsor::class)->handle($admin, $event, ['name' => 'Con oro', 'sponsor_category_id' => $gold->id]);
    $withSilver = app(CreateSponsor::class)->handle($admin, $event, ['name' => 'Con plata', 'sponsor_category_id' => $silver->id]);
    $without = app(CreateSponsor::class)->handle($admin, $event, ['name' => 'Sin nivel']);

    Auth::login($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);

    $component = Livewire::test(ManageEventSponsors::class, ['record' => $event->id])
        ->assertActionVisible('categories')
        ->assertSee('Oro')
        ->assertCanSeeTableRecords([$withGold, $withSilver, $without])
        ->filterTable('level', $gold->id)
        ->assertCanSeeTableRecords([$withGold])
        ->assertCanNotSeeTableRecords([$withSilver, $without])
        ->resetTableFilters();

    $component->callAction('createSponsor', ['name' => 'Nuevo', 'sponsor_category_id' => $silver->id])->assertHasNoActionErrors();

    expect(Sponsor::query()->where('name', 'Nuevo')->firstOrFail()->sponsor_category_id)->toBe($silver->id);
});

it('CA-030-17: the categories page answers 404 for an event of another organization, is reachable by the admin and keeps Sponsors active in the menu', function (): void {
    [$organizationA, $eventA, $adminA] = formScenario();
    [, $eventB] = formScenario();
    $url = EventResource::getUrl('sponsor-categories', ['record' => $eventA], panel: 'admin', tenant: $organizationA);

    $this->actingAs($adminA)->get($url)->assertOk()->assertSee(__('sponsors.categories.navigation'));
    $this->actingAs($adminA)->get(EventResource::getUrl('sponsor-categories', ['record' => $eventB], panel: 'admin', tenant: $organizationA))->assertNotFound();
});

it('CA-030-17: a reviewer has no access to the categories page', function (): void {
    [$organization, $event, $reviewer] = formScenario(OrganizationRole::Reviewer);
    Auth::login($reviewer);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    expect(ManageEventSponsorCategories::canAccess(['record' => $event]))->toBeFalse()
        ->and($this->actingAs($reviewer)->get(EventResource::getUrl('sponsor-categories', ['record' => $event], panel: 'admin', tenant: $organization))->status())->toBeIn([403, 404]);
});

it('CA-030-16: the data migration turns each distinct free-text level into a category of its event and assigns the sponsors', function (): void {
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);
    $otherEvent = Event::factory()->create(['organization_id' => $organization->id]);
    $existing = app(CreateSponsorCategory::class)->handle($admin, $otherEvent, ['name' => 'Bronce']);

    // La columna ya no existe en la base de pruebas: se recrea dentro de la transacción de la prueba (DDL transaccional).
    Schema::table('sponsors', fn ($table) => $table->string('level', 80)->nullable());

    $rows = [
        ['Oro', $event->id, 1], ['  oro ', $event->id, 2], ['Plata', $event->id, 3], ['', $event->id, 4], [null, $event->id, 5],
        ['bronce', $otherEvent->id, 1],
    ];
    $ids = [];

    foreach ($rows as [$level, $eventId, $position]) {
        $ids[] = $id = (string) fake()->uuid();
        DB::table('sponsors')->insert([
            'id' => $id, 'organization_id' => $organization->id, 'event_id' => $eventId, 'name' => "S{$position}-{$eventId}",
            'level' => $level, 'position' => $position, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    (require base_path('database/migrations/2026_11_29_100100_convert_sponsor_level_into_categories.php'))->up();
    // Idempotente: repetirla no duplica nada.
    (require base_path('database/migrations/2026_11_29_100100_convert_sponsor_level_into_categories.php'))->up();

    $categories = SponsorCategory::query()->where('event_id', $event->id)->orderBy('position')->get();
    $byId = fn (string $id): ?string => DB::table('sponsors')->where('id', $id)->value('sponsor_category_id');

    expect($categories->pluck('name')->all())->toBe(['Oro', 'Plata'])
        ->and($categories->pluck('position')->all())->toBe([1, 2])
        ->and($byId($ids[0]))->toBe($categories[0]->id)
        ->and($byId($ids[1]))->toBe($categories[0]->id)
        ->and($byId($ids[2]))->toBe($categories[1]->id)
        ->and($byId($ids[3]))->toBeNull()
        ->and($byId($ids[4]))->toBeNull()
        // Otro evento: la categoría que ya existía se reutiliza (sin distinguir mayúsculas), no se duplica.
        ->and(SponsorCategory::query()->where('event_id', $otherEvent->id)->count())->toBe(1)
        ->and($byId($ids[5]))->toBe($existing->id);

    // La reversa devuelve el nombre de la categoría al texto libre.
    (require base_path('database/migrations/2026_11_29_100100_convert_sponsor_level_into_categories.php'))->down();

    expect(DB::table('sponsors')->where('id', $ids[2])->value('level'))->toBe('Plata')
        ->and($byId($ids[2]))->toBeNull();
});

it('CA-030-16: dropping the level column and bringing it back works (reversible schema)', function (): void {
    expect(Schema::hasColumn('sponsors', 'level'))->toBeFalse()
        ->and(Schema::hasColumn('sponsors', 'sponsor_category_id'))->toBeTrue();

    $drop = require base_path('database/migrations/2026_11_29_100200_drop_sponsor_level_column.php');
    $drop->down();

    expect(Schema::hasColumn('sponsors', 'level'))->toBeTrue();
});
