<?php

declare(strict_types=1);

use App\Enums\CallStatus;
use App\Enums\FormDefinitionStatus;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Resources\Events\RelationManagers\CallsRelationManager;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\FormDefinition;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\SubmissionTypes;

require_once __DIR__.'/../../Support/form-helpers.php';

/*
 * Plan 022 F2: el formulario se asigna, cambia y quita desde la lista de convocatorias del evento.
 * Un solo rol por prueba: la caché de permisos de Spatie es compartida entre usuarios.
 */

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/** @return array{0: Organization, 1: Event, 2: User} sesión del panel con el rol indicado y el evento (es, en) de su organización */
function assignmentScenario(OrganizationRole $role = OrganizationRole::OrgAdmin): array
{
    [$organization, $event, $user] = formScenario($role);

    Auth::login($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    return [$organization, $event, $user];
}

/** @return Testable<CallsRelationManager> */
function assignmentManager(Event $event): Testable
{
    return Livewire::test(CallsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => ViewEvent::class]);
}

function assignmentCall(Organization $organization, Event $event, CallStatus $status = CallStatus::Draft): CallForSubmissions
{
    return CurrentOrganization::run($organization, fn (): CallForSubmissions => CallForSubmissions::factory()->create([
        'organization_id' => $organization->id,
        'event_id' => $event->id,
        'locales' => ['es'],
        'status' => $status,
    ]));
}

/** @return array<string, mixed> */
function assignmentCallData(Event $event): array
{
    return [
        'submission_type_id' => SubmissionTypes::for($event)->id,
        'title' => ['es' => 'Convocatoria de sesiones', 'en' => 'Call for sessions'],
        'locales' => ['es', 'en'],
        'opens_at' => '2027-01-10 09:00',
        'closes_at' => '2027-02-15 23:00',
    ];
}

function storedCallOf(Organization $organization, CallForSubmissions $call): CallForSubmissions
{
    return CurrentOrganization::run($organization, fn (): CallForSubmissions => CallForSubmissions::query()->findOrFail($call->id));
}

/**
 * Monta la acción, rellena su modal y la confirma. Se hace por pasos y no con `callAction($accion, $datos)`
 * porque con la acción de fila, cuyo modal es dinámico, ese atajo deja el modal abierto sin enviar los datos.
 *
 * @param  Testable<CallsRelationManager>  $manager
 * @param  array<string, mixed>  $data
 * @return Testable<CallsRelationManager>
 */
function submitAction(Testable $manager, TestAction $action, array $data): Testable
{
    return $manager->mountAction($action)->fillForm($data)->callMountedAction();
}

function storedFormOf(Organization $organization, FormDefinition $form): FormDefinition
{
    return CurrentOrganization::run($organization, fn (): FormDefinition => FormDefinition::query()->findOrFail($form->id));
}

/** Formulario en borrador con un campo válido (publicable). */
function draftFormOf(User $actor, Event $event, string $name = 'Formulario de sesiones'): FormDefinition
{
    return createForm($actor, $event, [formField('short_text')], $name);
}

it('CA-022-1: each call shows the name of its form or the no-form badge', function (): void {
    [$organization, $event, $admin] = assignmentScenario();
    $withForm = assignmentCall($organization, $event);
    $withoutForm = assignmentCall($organization, $event);
    assignPublishedForm($admin, $withForm);
    CurrentOrganization::set($organization);

    assignmentManager($event)
        ->assertTableColumnStateSet('form_definition_id', 'Formulario de sesiones', $withForm)
        ->assertTableColumnStateSet('form_definition_id', 'Sin formulario', $withoutForm);
});

it('CA-022-2: an admin assigns a draft form from the call and it ends up published and linked', function (): void {
    [$organization, $event, $admin] = assignmentScenario();
    $call = assignmentCall($organization, $event);
    $form = draftFormOf($admin, $event);
    CurrentOrganization::set($organization);

    submitAction(assignmentManager($event), TestAction::make('assignForm')->table($call), ['form_definition_id' => $form->id])
        ->assertHasNoFormErrors();

    expect(storedCallOf($organization, $call)->form_definition_id)->toBe($form->id)
        ->and(storedFormOf($organization, $form)->status)->toBe(FormDefinitionStatus::Published);
});

it('CA-022-3: the modal warns that a draft form will be published, and a form without fields is refused', function (): void {
    [$organization, $event, $admin] = assignmentScenario();
    $call = assignmentCall($organization, $event);
    $draft = draftFormOf($admin, $event, 'Borrador listo');
    $empty = createForm($admin, $event, [], 'Borrador vacio');
    $published = assignPublishedForm($admin, assignmentCall($organization, $event));
    CurrentOrganization::set($organization);
    $action = TestAction::make('assignForm')->table($call);

    assignmentManager($event)
        ->mountAction($action)
        ->fillForm(['form_definition_id' => $draft->id])
        ->assertSchemaComponentVisible('draft_notice')
        ->fillForm(['form_definition_id' => $published->id])
        ->assertSchemaComponentHidden('draft_notice');

    submitAction(assignmentManager($event), $action, ['form_definition_id' => $empty->id]);

    expect(storedCallOf($organization, $call)->form_definition_id)->toBeNull()
        ->and(storedFormOf($organization, $empty)->status)->toBe(FormDefinitionStatus::Draft);
});

it('CA-022-4: the selector only offers forms of the same event', function (): void {
    [$organization, $event, $admin] = assignmentScenario();
    $call = assignmentCall($organization, $event);
    draftFormOf($admin, $event, 'Propio');
    $otherEvent = Event::factory()->create(['organization_id' => $organization->id]);
    $foreign = draftFormOf($admin, $otherEvent, 'De otro evento');
    CurrentOrganization::set($organization);
    $action = TestAction::make('assignForm')->table($call);

    assignmentManager($event)
        ->mountAction($action)
        ->assertFormFieldExists('form_definition_id', fn (Select $field): bool => array_map(
            fn (string $label): string => str($label)->before(' · ')->toString(),
            array_values($field->getOptions()),
        ) === ['Propio']);

    submitAction(assignmentManager($event), $action, ['form_definition_id' => $foreign->id])
        ->assertHasFormErrors(['form_definition_id']);

    expect(storedCallOf($organization, $call)->form_definition_id)->toBeNull();
});

it('CA-022-6: without forms.manage the assign action is not shown', function (): void {
    // Ningún rol integrado ve las convocatorias sin gestionar formularios: se le da solo `calls.view` al revisor.
    [$organization, $event, $reviewer] = assignmentScenario(OrganizationRole::Reviewer);
    CurrentOrganization::run($organization, fn () => $reviewer->givePermissionTo('calls.view'));
    $call = assignmentCall($organization, $event);

    assignmentManager($event)
        ->assertActionHidden(TestAction::make('assignForm')->table($call));
});

it('CA-022-8 and CA-022-9: the unassign action only shows on a draft call with a form, and removes it', function (): void {
    [$organization, $event, $admin] = assignmentScenario();
    $draftWithForm = assignmentCall($organization, $event);
    $draftWithout = assignmentCall($organization, $event);
    $published = assignmentCall($organization, $event);
    assignPublishedForm($admin, $draftWithForm);
    assignPublishedForm($admin, $published);
    CurrentOrganization::run($organization, fn () => CallForSubmissions::query()->whereKey($published->id)->update(['status' => CallStatus::Published]));
    CurrentOrganization::set($organization);

    assignmentManager($event)
        ->assertActionHidden(TestAction::make('unassignForm')->table($draftWithout))
        ->assertActionHidden(TestAction::make('unassignForm')->table($published))
        ->assertActionVisible(TestAction::make('assignForm')->table($published))
        ->assertActionVisible(TestAction::make('unassignForm')->table($draftWithForm))
        ->callAction(TestAction::make('unassignForm')->table($draftWithForm));

    expect(storedCallOf($organization, $draftWithForm)->form_definition_id)->toBeNull();
});

it('CA-022-10: changing the form warns that sent proposals keep their copy and leaves them untouched', function (): void {
    [$organization, $event, $admin] = assignmentScenario();
    $call = assignmentCall($organization, $event, CallStatus::Published);
    $old = assignPublishedForm($admin, $call);
    $new = draftFormOf($admin, $event, 'Formulario nuevo');
    $submission = CurrentOrganization::run($organization, fn (): Submission => Submission::factory()->create([
        'organization_id' => $organization->id,
        'event_id' => $event->id,
        'call_for_submissions_id' => $call->id,
        'form_definition_id' => $old->id,
        'form_snapshot' => ['fields' => [['key' => 'titulo']]],
    ]));
    CurrentOrganization::set($organization);
    $action = TestAction::make('assignForm')->table($call);

    assignmentManager($event)->mountAction($action)->assertSchemaComponentVisible('submissions_notice');
    submitAction(assignmentManager($event), $action, ['form_definition_id' => $new->id]);

    $stored = CurrentOrganization::run($organization, fn (): Submission => Submission::query()->findOrFail($submission->id));

    expect(storedCallOf($organization, $call)->form_definition_id)->toBe($new->id)
        ->and($stored->form_definition_id)->toBe($old->id)
        ->and($stored->form_snapshot)->toBe(['fields' => [['key' => 'titulo']]]);
});

it('CA-022-11: creating a call can assign a form of the event, and leaving it empty leaves the call without one', function (): void {
    [$organization, $event, $admin] = assignmentScenario();
    $form = draftFormOf($admin, $event);
    $empty = createForm($admin, $event, [], 'Borrador vacio');
    CurrentOrganization::set($organization);
    $create = TestAction::make('createCall')->table();

    submitAction(assignmentManager($event), $create, [...assignmentCallData($event), 'form_definition_id' => $form->id])
        ->assertHasNoFormErrors();
    submitAction(assignmentManager($event), $create, assignmentCallData($event));
    // Una asignación que falla no deshace el alta: la convocatoria se crea sin formulario.
    submitAction(assignmentManager($event), $create, [...assignmentCallData($event), 'form_definition_id' => $empty->id]);

    $calls = CallForSubmissions::query()->get();

    expect($calls)->toHaveCount(3)
        ->and($calls->where('form_definition_id', $form->id))->toHaveCount(1)
        ->and($calls->whereNull('form_definition_id'))->toHaveCount(2)
        ->and(storedFormOf($organization, $form)->status)->toBe(FormDefinitionStatus::Published);
});

it('CA-022-12: the same form can be assigned to two calls of the event', function (): void {
    [$organization, $event, $admin] = assignmentScenario();
    $first = assignmentCall($organization, $event);
    $second = assignmentCall($organization, $event);
    $form = draftFormOf($admin, $event);
    CurrentOrganization::set($organization);

    submitAction(assignmentManager($event), TestAction::make('assignForm')->table($first), ['form_definition_id' => $form->id]);
    submitAction(assignmentManager($event), TestAction::make('assignForm')->table($second), ['form_definition_id' => $form->id]);

    expect(storedCallOf($organization, $first)->form_definition_id)->toBe($form->id)
        ->and(storedCallOf($organization, $second)->form_definition_id)->toBe($form->id);
});
