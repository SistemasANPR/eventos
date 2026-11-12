<?php

declare(strict_types=1);

use App\Enums\FormDefinitionStatus;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Forms\Pages\CreateFormDefinition;
use App\Filament\Resources\Forms\Pages\EditFormDefinition;
use App\Filament\Resources\Forms\Pages\ListFormDefinitions;
use App\Models\CallForSubmissions;
use App\Models\FormDefinition;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/form-helpers.php';

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

function formPanelSession(Organization $organization, User $user): void
{
    Auth::login($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);
}

/** @return FormDefinition el formulario guardado, leído dentro de su organización */
function storedForm(Organization $organization, string $id): FormDefinition
{
    return CurrentOrganization::run($organization, fn (): FormDefinition => FormDefinition::query()->findOrFail($id));
}

it('CA-005-1: in the panel an admin creates a form with fields and every field gets a server-generated key', function (): void {
    [$organization, $event, $admin] = formScenario();
    formPanelSession($organization, $admin);

    Livewire::test(CreateFormDefinition::class)
        ->fillForm([
            'event_id' => $event->id,
            'name' => 'Formulario de sesiones',
            'fields' => [
                ['type' => 'short_text', 'required' => true, 'label' => ['es' => 'Título', 'en' => 'Title'], 'config' => ['maxLength' => 200]],
                ['type' => 'single_choice', 'required' => false, 'label' => ['es' => 'Eje'], 'config' => ['options' => [
                    ['label' => ['es' => 'Ciencia']],
                    ['label' => ['es' => 'Arte']],
                ]]],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $form = storedForm($organization, FormDefinition::query()->withoutGlobalScopes()->sole()->id);
    $keys = array_column($form->fields, 'key');

    expect($form->name)->toBe('Formulario de sesiones')
        ->and($form->event_id)->toBe($event->id)
        ->and($keys)->each->toMatch('/^f_[a-z0-9]{6,}$/')
        ->and(array_column($form->fields, 'type'))->toBe(['short_text', 'single_choice'])
        ->and($form->fields[0]['config']['maxLength'])->toBe(200)
        ->and(array_column($form->fields[1]['config']['options'], 'value'))->each->toMatch('/^o_[a-z0-9]{6,}$/');
});

it('CA-005-7: in the panel editing a form keeps the key of every field and the value of every option', function (): void {
    [$organization, $event, $admin] = formScenario();
    $created = createForm($admin, $event, [formField('short_text'), formField('single_choice')]);
    $keys = array_column($created->fields, 'key');
    $values = array_column($created->fields[1]['config']['options'], 'value');
    formPanelSession($organization, $admin);

    Livewire::test(EditFormDefinition::class, ['record' => $created->id])
        ->fillForm(['name' => 'Renombrado'])
        ->call('save')
        ->assertHasNoFormErrors();

    $form = storedForm($organization, $created->id);

    expect($form->name)->toBe('Renombrado')
        ->and(array_column($form->fields, 'key'))->toBe($keys)
        ->and(array_column($form->fields[1]['config']['options'], 'value'))->toBe($values);
});

it('CA-005-3: in the panel a field without a label in the base language shows the error and saves nothing', function (): void {
    [$organization, $event, $admin] = formScenario();
    formPanelSession($organization, $admin);

    Livewire::test(CreateFormDefinition::class)
        ->fillForm([
            'event_id' => $event->id,
            'name' => 'Sin etiqueta',
            'fields' => [['type' => 'short_text', 'label' => ['en' => 'Only english']]],
        ])
        ->call('create')
        ->assertHasFormErrors();

    expect(FormDefinition::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('CA-005-6: the list shows only the forms of the organization', function (): void {
    [$organizationA, $eventA, $adminA] = formScenario();
    [, $eventB, $adminB] = formScenario();
    $own = createForm($adminA, $eventA, [formField('short_text')], 'Propio');
    $foreign = createForm($adminB, $eventB, [formField('short_text')], 'Ajeno');
    formPanelSession($organizationA, $adminA);

    Livewire::test(ListFormDefinitions::class)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$foreign]);
});

it('CA-005-6: a reviewer cannot open the forms and the form of another organization answers 404', function (): void {
    [$organizationA, $eventA, $adminA] = formScenario();
    [, $eventB, $adminB] = formScenario();
    $reviewer = Memberships::attach(User::factory()->create(), $organizationA, OrganizationRole::Reviewer);
    $foreign = createForm($adminB, $eventB, [formField('short_text')]);

    expect($this->actingAs($reviewer)->get("/admin/{$organizationA->slug}/forms/form-definitions")->status())->toBeIn([403, 404]);

    $own = createForm($adminA, $eventA, [formField('short_text')]);

    // Control: la misma ruta con un formulario propio responde 200; así el 404 de abajo es por el aislamiento y no por una URL mal escrita.
    $this->actingAs($adminA)->get("/admin/{$organizationA->slug}/forms/form-definitions/{$own->id}/edit")->assertOk();
    $this->actingAs($adminA)->get("/admin/{$organizationA->slug}/forms/form-definitions/{$foreign->id}/edit")->assertNotFound();
});

it('CA-005-16: in the panel an admin publishes the form choosing a call of its event', function (): void {
    [$organization, $event, $admin] = formScenario();
    $form = createForm($admin, $event, [formField('short_text')]);
    $call = CurrentOrganization::run($organization, fn () => CallForSubmissions::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id]));
    formPanelSession($organization, $admin);

    Livewire::test(EditFormDefinition::class, ['record' => $form->id])
        ->callAction('publish', ['call_id' => $call->id])
        ->assertHasNoActionErrors();

    $storedCall = CurrentOrganization::run($organization, fn () => CallForSubmissions::query()->findOrFail($call->id));

    expect(storedForm($organization, $form->id)->status)->toBe(FormDefinitionStatus::Published)
        ->and($storedCall->form_definition_id)->toBe($form->id);
});

it('CA-005-22: in the panel saving with an outdated version keeps the other change', function (): void {
    [$organization, $event, $admin] = formScenario();
    $form = createForm($admin, $event, [formField('short_text')]);
    formPanelSession($organization, $admin);

    Livewire::test(EditFormDefinition::class, ['record' => $form->id])
        ->fillForm(['name' => 'Cambio tardio', 'version' => 99])
        ->call('save');

    expect(storedForm($organization, $form->id)->name)->toBe('Formulario de sesiones');
});
