<?php

declare(strict_types=1);

use App\Enums\FormDefinitionStatus;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\ManageEventFormDefinitions;
use App\Filament\Resources\Forms\Pages\CreateFormDefinition;
use App\Models\Event;
use App\Models\FormDefinition;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/form-helpers.php';

/*
 * Plan 020, F1: los formularios viven bajo el evento (`/{tenant}/events/{event}/form-definitions`).
 * Cada prueba usa un solo rol: la caché de permisos de Spatie se comparte dentro del proceso.
 */

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

it('CA-020-6: the list of an event shows only its own forms and answers 404 for an event or a form of another scope', function (): void {
    [$organizationA, $eventA, $adminA] = formScenario();
    $eventB = Event::factory()->create(['organization_id' => $organizationA->id]);
    [, $eventX, $adminX] = formScenario();
    $inA = createForm($adminA, $eventA, [formField('short_text')], 'Form del evento A');
    $inB = createForm($adminA, $eventB, [formField('short_text')], 'Form del evento B');
    $inX = createForm($adminX, $eventX, [formField('short_text')], 'Form de otra organizacion');

    $this->actingAs($adminA)->get(eventFormsUrl($organizationA, $eventA))
        ->assertOk()
        ->assertSee('Form del evento A')
        ->assertDontSee('Form del evento B')
        ->assertDontSee('Form de otra organizacion');

    // Control: la ruta del formulario propio responde 200; así los 404 de abajo son por el aislamiento y no por una URL mal escrita.
    $this->actingAs($adminA)->get(eventFormUrl($organizationA, $eventA, 'edit', $inA))->assertOk();

    $this->actingAs($adminA)->get(eventFormsUrl($organizationA, $eventX))->assertNotFound();
    $this->actingAs($adminA)->get(eventFormUrl($organizationA, $eventA, 'edit', $inB))->assertNotFound();
    $this->actingAs($adminA)->get(eventFormUrl($organizationA, $eventA, 'preview', $inB))->assertNotFound();
    $this->actingAs($adminA)->get(eventFormUrl($organizationA, $eventA, 'edit', $inX))->assertNotFound();
    $this->actingAs($adminA)->get(eventFormUrl($organizationA, $eventA, 'preview', $inX))->assertNotFound();
});

it('CA-020-7: a form created from the page of an event is born in that event', function (): void {
    [$organization, $eventA, $admin] = formScenario();
    $eventB = Event::factory()->create(['organization_id' => $organization->id]);
    Auth::login($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);
    withEventRoute($eventA);

    Livewire::test(CreateFormDefinition::class)
        ->fillForm(['name' => 'Nace en A', 'fields' => [['type' => 'short_text', 'required' => true, 'label' => ['es' => 'Título'], 'config' => ['maxLength' => 100]]]])
        ->call('create')
        ->assertHasNoFormErrors();

    $form = CurrentOrganization::run($organization, fn (): FormDefinition => FormDefinition::query()->sole());

    expect($form->event_id)->toBe($eventA->id)
        ->and($form->event_id)->not->toBe($eventB->id)
        ->and($form->organization_id)->toBe($organization->id);
});

it('CA-020-7: the create page of an event offers no event selector', function (): void {
    [$organization, $event, $admin] = formScenario();

    // La ruta cuelga de `form-definitions`, igual que la lista del evento (sin el `forms/` del espacio de nombres).
    expect(eventFormUrl($organization, $event, 'create'))->toEndWith("/events/{$event->id}/form-definitions/create")
        ->and(eventFormsUrl($organization, $event))->toEndWith("/events/{$event->id}/form-definitions");

    $this->actingAs($admin)->get(eventFormUrl($organization, $event, 'create'))
        ->assertOk()
        ->assertSee('data.name', false)
        ->assertDontSee('data.event_id', false);
});

it('CA-020-8: a form created from a template on the page of an event is born in that event', function (): void {
    [$organization, $eventA, $admin] = formScenario();
    $eventB = Event::factory()->create(['organization_id' => $organization->id]);
    Auth::login($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    Livewire::test(ManageEventFormDefinitions::class, ['record' => $eventB->id])
        ->callAction('fromTemplate', ['template' => 'panel'])
        ->assertHasNoActionErrors();

    $form = CurrentOrganization::run($organization, fn (): FormDefinition => FormDefinition::query()->sole());

    expect($form->event_id)->toBe($eventB->id)
        ->and($form->event_id)->not->toBe($eventA->id);
});

it('CA-020-9: duplicating a form keeps the copy in the same event and redirects to its nested edit page', function (): void {
    [$organization, $event, $admin] = formScenario();
    $source = createForm($admin, $event, [formField('short_text')], 'Base');
    Auth::login($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    Livewire::test(ManageEventFormDefinitions::class, ['record' => $event->id])
        ->callTableAction('duplicate', $source);

    $copy = CurrentOrganization::run($organization, fn (): FormDefinition => FormDefinition::query()->where('name', 'Base (copia)')->sole());

    expect($copy->event_id)->toBe($event->id)
        ->and($copy->status)->toBe(FormDefinitionStatus::Draft)
        ->and($copy->id)->not->toBe($source->id);

    $this->actingAs($admin)->get(eventFormUrl($organization, $event, 'edit', $copy))->assertOk();
});

it('CA-020-10: a member without forms.view or forms.manage gets 403 and without a session is sent to the login', function (): void {
    [$organization, $event] = formScenario();
    $reviewer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);

    $this->get(eventFormsUrl($organization, $event))->assertRedirect();
    $this->actingAs($reviewer)->get(eventFormsUrl($organization, $event))->assertForbidden();
});

it('CA-020-10: forms.view alone is not enough: the event itself must be accessible (inherited from the event)', function (): void {
    [$organization, $event, $admin] = formScenario();
    createForm($admin, $event, [formField('short_text')]);
    $viewer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);
    Permission::findOrCreate('forms.view', 'web');
    CurrentOrganization::run($organization, fn () => $viewer->givePermissionTo('forms.view'));

    $this->actingAs($viewer->refresh())->get(eventFormsUrl($organization, $event))->assertForbidden();
});

it('CA-020-10: a member with forms.view and access to the event sees the list but cannot open the create page', function (): void {
    [$organization, $event, $admin] = formScenario();
    createForm($admin, $event, [formField('short_text')], 'Visible para consulta');
    $viewer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);
    Permission::findOrCreate('forms.view', 'web');
    Permission::findOrCreate('events.manage', 'web');
    CurrentOrganization::run($organization, fn () => $viewer->givePermissionTo(['forms.view', 'events.manage']));
    $viewer = $viewer->refresh();

    $this->actingAs($viewer)->get(eventFormsUrl($organization, $event))->assertOk()->assertSee('Visible para consulta');
    $this->actingAs($viewer)->get(eventFormUrl($organization, $event, 'create'))->assertForbidden();
});

it('CA-020-11: a member without a role gets 403 on the forms of an event', function (): void {
    [$organization, $event] = formScenario();
    $member = Memberships::attach(User::factory()->create(), $organization);

    $this->actingAs($member)->get(eventFormsUrl($organization, $event))->assertForbidden();
});

it('CA-020-12: the event sub-navigation offers Forms only to who can access them', function (): void {
    [$organization, $event, $reviewer] = formScenario(OrganizationRole::Reviewer);
    Auth::login($reviewer);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    // La sub-navegación solo ofrece una entrada si la página se puede acceder (`generateNavigationItems`).
    expect(ManageEventFormDefinitions::canAccess(['record' => $event]))->toBeFalse();
});

it('CA-020-12: the event sub-navigation offers Forms to an organizer', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    Auth::login($organizer);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    expect(ManageEventFormDefinitions::canAccess(['record' => $event]))->toBeTrue();
});

it('CA-020-13: the old top-level forms routes no longer exist', function (): void {
    [$organization, $event, $admin] = formScenario();
    $form = createForm($admin, $event, [formField('short_text')]);
    $old = "/admin/{$organization->slug}/forms/form-definitions";

    foreach ([$old, "{$old}/create", "{$old}/{$form->id}/edit", "{$old}/{$form->id}/preview"] as $url) {
        $this->actingAs($admin)->get($url)->assertNotFound();
    }
});

it('CA-020-15: the event view offers the sub-navigation with Summary and Forms', function (): void {
    [$organization, $event, $admin] = formScenario();

    $this->actingAs($admin)->get(EventResource::getUrl('view', ['record' => $event], panel: 'admin', tenant: $organization))
        ->assertOk()
        ->assertSee(__('events.sub_navigation.summary'))
        ->assertSee(__('forms.navigation'))
        ->assertSee(eventFormsUrl($organization, $event), false);
});
