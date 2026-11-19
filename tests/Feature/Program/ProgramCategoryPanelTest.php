<?php

declare(strict_types=1);

use App\Actions\Program\CreateProgramCategory;
use App\Enums\OrganizationRole;
use App\Enums\ProgramCategoryKind;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\ManageEventActivities;
use App\Filament\Resources\Events\Pages\ManageEventActivityCategories;
use App\Filament\Resources\Events\Pages\ManageEventProgramCategories;
use App\Filament\Resources\Events\Pages\ManageEventSpeakerCategories;
use App\Filament\Resources\Events\Pages\ManageEventSpeakers;
use App\Filament\Resources\Events\ProgramCategoryShortcuts;
use App\Models\Event;
use App\Models\Organization;
use App\Models\SpeakerCategory;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/form-helpers.php';

/*
 * Plan 021, F1: «Categorías de ponentes» y «Categorías de actividades» como secciones del evento.
 * Cada prueba usa un solo rol: la caché de permisos de Spatie se comparte dentro del proceso.
 */

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

dataset('catalog pages', [
    'speakers' => [ManageEventSpeakerCategories::class, ProgramCategoryKind::Speaker, 'speaker-categories'],
    'activities' => [ManageEventActivityCategories::class, ProgramCategoryKind::Activity, 'activity-categories'],
]);

/**
 * @param  class-string<ManageEventProgramCategories>  $page
 * @return Testable<ManageEventProgramCategories>
 */
function categoryPage(string $page, Organization $organization, User $user, Event $event): Testable
{
    Auth::login($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    return Livewire::test($page, ['record' => $event->id]);
}

it('CA-021-6: the empty catalog shows its empty state and the admin creates, renames and deletes a category', function (string $page, ProgramCategoryKind $kind): void {
    [$organization, $event, $admin] = formScenario();
    $model = $kind->modelClass();

    $component = categoryPage($page, $organization, $admin, $event)
        ->assertSee(__("program.categories.{$kind->value}.empty_heading"));

    $component->callAction('createCategory', ['name' => 'Académico'])->assertHasNoActionErrors();
    $category = $model::query()->where('event_id', $event->id)->firstOrFail();

    $component->assertCanSeeTableRecords([$category])
        ->callAction(TestAction::make('editCategory')->table($category), ['name' => 'Ciencia'])
        ->assertHasNoActionErrors();

    expect($category->refresh()->name)->toBe('Ciencia');

    $component->callAction(TestAction::make('deleteCategory')->table($category));

    expect($model::query()->count())->toBe(0);
})->with('catalog pages');

it('CA-021-6: a repeated name is refused with an error and nothing is created', function (string $page, ProgramCategoryKind $kind): void {
    [$organization, $event, $admin] = formScenario();
    app(CreateProgramCategory::class)->handle($admin, $event, $kind, ['name' => 'Académico']);

    categoryPage($page, $organization, $admin, $event)
        ->callAction('createCategory', ['name' => 'ACADÉMICO'])
        ->assertNotified(__('program.categories.failed'));

    expect($kind->modelClass()::query()->count())->toBe(1);
})->with('catalog pages');

it('CA-021-6: the catalog lists only the categories of its event and of its kind', function (): void {
    [$organization, $event, $admin] = formScenario();
    $otherEvent = Event::factory()->create(['organization_id' => $organization->id]);
    $own = app(CreateProgramCategory::class)->handle($admin, $event, ProgramCategoryKind::Speaker, ['name' => 'Propia']);
    $ofOtherEvent = app(CreateProgramCategory::class)->handle($admin, $otherEvent, ProgramCategoryKind::Speaker, ['name' => 'De otro evento']);
    $activity = app(CreateProgramCategory::class)->handle($admin, $event, ProgramCategoryKind::Activity, ['name' => 'De actividades']);

    categoryPage(ManageEventSpeakerCategories::class, $organization, $admin, $event)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$ofOtherEvent]);

    categoryPage(ManageEventActivityCategories::class, $organization, $admin, $event)
        ->assertCanSeeTableRecords([$activity])
        ->assertCanNotSeeTableRecords([$own]);
});

it('CA-021-15: an organizer creates and renames but is not offered the delete action', function (string $page, ProgramCategoryKind $kind): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    $category = app(CreateProgramCategory::class)->handle($organizer, $event, $kind, ['name' => 'Académico']);

    categoryPage($page, $organization, $organizer, $event)
        ->assertActionVisible('createCategory')
        ->assertActionVisible(TestAction::make('editCategory')->table($category))
        ->assertActionHidden(TestAction::make('deleteCategory')->table($category));
})->with('catalog pages');

it('CA-021-15: a reviewer has no access to either catalog', function (string $page): void {
    /** @var class-string<ManageEventProgramCategories> $page */
    [$organization, $event, $reviewer] = formScenario(OrganizationRole::Reviewer);
    Auth::login($reviewer);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    expect($page::canAccess(['record' => $event]))->toBeFalse();
})->with(['speakers' => ManageEventSpeakerCategories::class, 'activities' => ManageEventActivityCategories::class]);

it('CA-021-15: a reviewer cannot reach either catalog by URL', function (): void {
    [$organization, $event, $reviewer] = formScenario(OrganizationRole::Reviewer);

    foreach (['speaker-categories', 'activity-categories'] as $page) {
        expect($this->actingAs($reviewer)->get(EventResource::getUrl($page, ['record' => $event], panel: 'admin', tenant: $organization))->status())->toBeIn([403, 404]);
    }
});

it('CA-021-15: the admin reaches both catalogs by URL, and the sidebar no longer lists them as sections', function (): void {
    [$organization, $event, $admin] = formScenario();

    foreach (['speaker-categories', 'activity-categories'] as $page) {
        $url = EventResource::getUrl($page, ['record' => $event], panel: 'admin', tenant: $organization);

        $this->actingAs($admin)->get($url)->assertOk();
    }

    $this->actingAs($admin)->get(EventResource::getUrl('view', ['record' => $event], panel: 'admin', tenant: $organization))
        ->assertOk()
        ->assertDontSee(EventResource::getUrl('speaker-categories', ['record' => $event], panel: 'admin', tenant: $organization), false)
        ->assertDontSee(EventResource::getUrl('activity-categories', ['record' => $event], panel: 'admin', tenant: $organization), false);
});

it('the Categories button of Speakers and Activities links to its catalog and shows how many categories it has', function (string $list, ProgramCategoryKind $kind, string $catalog): void {
    [$organization, $event, $admin] = formScenario();
    app(CreateProgramCategory::class)->handle($admin, $event, $kind, ['name' => 'Académico']);
    app(CreateProgramCategory::class)->handle($admin, $event, $kind, ['name' => 'Industria']);

    Auth::login($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    Livewire::test($list, ['record' => $event->id])
        ->assertActionVisible('categories')
        ->assertActionHasUrl('categories', EventResource::getUrl($catalog, ['record' => $event]))
        ;

    expect(ProgramCategoryShortcuts::button($event, $kind)->getBadge())->toBe('2');
})->with([
    'speakers' => [ManageEventSpeakers::class, ProgramCategoryKind::Speaker, 'speaker-categories'],
    'activities' => [ManageEventActivities::class, ProgramCategoryKind::Activity, 'activity-categories'],
]);

it('each catalog offers a way back to the list it belongs to', function (string $page, string $list): void {
    [$organization, $event, $admin] = formScenario();

    categoryPage($page, $organization, $admin, $event)
        ->assertActionVisible('back')
        ->assertActionHasUrl('back', EventResource::getUrl($list, ['record' => $event]));
})->with([
    'speakers' => [ManageEventSpeakerCategories::class, 'speakers'],
    'activities' => [ManageEventActivityCategories::class, 'activities'],
]);

it('a user without access to the catalog does not see the Categories button', function (): void {
    [$organization, $event, $reviewer] = formScenario(OrganizationRole::Reviewer);
    Auth::login($reviewer);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    expect(Gate::allows('viewAny', SpeakerCategory::class))->toBeFalse();
});

it('a category created from the speaker form is stored in the event and selected', function (): void {
    [$organization, $event, $admin] = formScenario();
    Auth::login($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    Livewire::test(ManageEventSpeakers::class, ['record' => $event->id])
        ->mountAction('createSpeaker')
        ->callAction(TestAction::make('createOption')->schemaComponent('category_ids', schema: 'mountedActionSchema0'), ['name' => 'Industria']);

    expect(SpeakerCategory::query()->where('event_id', $event->id)->pluck('name')->all())->toBe(['Industria']);
});

it('CA-021-17: the catalogs of an event of another organization answer 404', function (): void {
    [$organizationA, , $adminA] = formScenario();
    [, $eventB] = formScenario();

    foreach (['speaker-categories', 'activity-categories'] as $page) {
        // Control: la prueba de arriba abre la misma ruta con un evento propio y responde 200; este 404 es por el aislamiento.
        $this->actingAs($adminA)->get(EventResource::getUrl($page, ['record' => $eventB], panel: 'admin', tenant: $organizationA))->assertNotFound();
    }
});

it('CA-021-17: the category list never shows the categories of another organization', function (): void {
    [$organizationA, $eventA, $adminA] = formScenario();
    [, $eventB, $adminB] = formScenario();
    $foreign = app(CreateProgramCategory::class)->handle($adminB, $eventB, ProgramCategoryKind::Speaker, ['name' => 'Ajena']);
    $own = app(CreateProgramCategory::class)->handle($adminA, $eventA, ProgramCategoryKind::Speaker, ['name' => 'Propia']);

    categoryPage(ManageEventSpeakerCategories::class, $organizationA, $adminA, $eventA)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([SpeakerCategory::query()->withoutGlobalScopes()->findOrFail($foreign->id)]);
});
