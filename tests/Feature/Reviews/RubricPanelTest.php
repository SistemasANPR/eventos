<?php

declare(strict_types=1);

use App\Actions\Reviews\PublishRubric;
use App\Actions\Reviews\SaveRubric;
use App\Enums\OrganizationRole;
use App\Enums\RubricStatus;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\ManageEventRubrics;
use App\Filament\Resources\Rubrics\Pages\CreateRubric;
use App\Filament\Resources\Rubrics\Pages\EditRubric;
use App\Filament\Resources\Rubrics\RubricResource;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Rubric;
use App\Models\SubmissionType;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\Support\Memberships;
use Tests\Support\RubricUsage;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/form-helpers.php';

/*
 * Plan 011, F4b: la pantalla «Rúbricas» del evento. Delgada: las reglas y la autorización viven en
 * `SaveRubric`, `PublishRubric` y `RubricPolicy` (RubricTest); aquí se comprueba lo que la pantalla
 * muestra, que invoca esas Actions y que respeta el aislamiento entre organizaciones.
 */

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/** Sesión del panel con la ruta del evento simulada para las páginas anidadas bajo él. */
function rubricPanelSession(Organization $organization, User $user, Event $event): void
{
    Auth::login($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);
    withEventRoute($event);
}

/** @return array{0: Organization, 1: Event, 2: User, 3: SubmissionType} */
function rubricPanelScenario(OrganizationRole $role = OrganizationRole::Organizer): array
{
    [$organization, $event, $user] = formScenario($role);
    $type = SubmissionType::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id, 'name' => ['es' => 'Ponencia', 'en' => 'Talk']]);

    return [$organization, $event, $user, $type];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed> datos del formulario con un criterio de calificación y otro de texto
 */
function rubricFormData(SubmissionType $type, array $overrides = []): array
{
    return array_replace([
        'submission_type_id' => $type->id,
        'min_reviewers' => 2,
        'discrepancy_threshold' => 2,
        'blind_review' => false,
        'criteria' => [
            ['label' => ['es' => 'Relevancia', 'en' => 'Relevance'], 'kind' => 'scale', 'scale_min' => 1, 'scale_max' => 5, 'weight' => 2],
            ['label' => ['es' => 'Comentarios'], 'kind' => 'text', 'weight' => 1],
        ],
    ], $overrides);
}

/** @return Rubric la rúbrica guardada por la Action, para partir de una existente */
function storedRubric(User $actor, Event $event, SubmissionType $type, bool $published = false): Rubric
{
    $rubric = app(SaveRubric::class)->handle($actor, $event, rubricFormData($type));

    if ($published) {
        $rubric = app(PublishRubric::class)->handle($actor, $rubric);
    }

    return $rubric;
}

it('CA-011-1: in the panel an organizer creates a draft rubric with ordered translatable criteria', function (): void {
    [$organization, $event, $organizer, $type] = rubricPanelScenario();
    rubricPanelSession($organization, $organizer, $event);

    Livewire::test(CreateRubric::class)
        ->fillForm(rubricFormData($type))
        ->call('create')
        ->assertHasNoFormErrors();

    $rubric = CurrentOrganization::run($organization, fn (): Rubric => Rubric::query()->with('criteria')->sole());

    expect($rubric->status)->toBe(RubricStatus::Draft)
        ->and($rubric->event_id)->toBe($event->id)
        ->and($rubric->version)->toBe(1)
        ->and($rubric->criteria->pluck('position')->all())->toBe([1, 2])
        ->and($rubric->criteria[0]->getTranslations('label'))->toEqual(['es' => 'Relevancia', 'en' => 'Relevance']);
});

it('CA-011-2: in the panel an invalid rubric shows the errors and stores nothing', function (string $field, Closure $mutate): void {
    [$organization, $event, $organizer, $type] = rubricPanelScenario();
    rubricPanelSession($organization, $organizer, $event);

    Livewire::test(CreateRubric::class)
        ->fillForm($mutate(rubricFormData($type)))
        ->call('create')
        ->assertHasFormErrors([$field]);

    expect(Rubric::query()->withoutGlobalScopes()->count())->toBe(0);
})->with([
    'threshold zero' => ['discrepancy_threshold', fn (array $d): array => [...$d, 'discrepancy_threshold' => 0]],
    'threshold above the scale span (1 to 5 spans 4)' => ['discrepancy_threshold', fn (array $d): array => [...$d, 'discrepancy_threshold' => 5]],
    'min_reviewers zero' => ['min_reviewers', fn (array $d): array => [...$d, 'min_reviewers' => 0]],
]);

it('CA-011-2: in the panel a criterion with weight zero or an inverted scale is rejected next to its field', function (string $key, mixed $value): void {
    [$organization, $event, $organizer, $type] = rubricPanelScenario();
    rubricPanelSession($organization, $organizer, $event);
    $data = rubricFormData($type);
    $data['criteria'][0][$key] = $value;

    $component = Livewire::test(CreateRubric::class)->fillForm($data)->call('create');

    $uuid = array_key_first($component->get('data.criteria'));
    $component->assertHasFormErrors(["criteria.{$uuid}.".($key === 'weight' ? 'weight' : 'scale_max')]);

    expect(Rubric::query()->withoutGlobalScopes()->count())->toBe(0);
})->with([
    'weight zero' => ['weight', 0],
    'weight negative' => ['weight', -1],
    'scale_max equal to scale_min' => ['scale_max', 1],
]);

it('CA-011-3: in the panel editing a published rubric with recorded reviews creates version 2 and leaves version 1 intact', function (): void {
    [$organization, $event, $organizer, $type] = rubricPanelScenario();
    $first = storedRubric($organizer, $event, $type, published: true);
    RubricUsage::install($first);
    rubricPanelSession($organization, $organizer, $event);

    Livewire::test(EditRubric::class, ['record' => $first->id])
        ->fillForm(['min_reviewers' => 3])
        ->call('save')
        ->assertHasNoFormErrors();

    $rubrics = CurrentOrganization::run($organization, fn () => Rubric::query()->orderBy('version')->get());

    expect($rubrics)->toHaveCount(2)
        ->and($rubrics[0]->min_reviewers)->toBe(2)
        ->and($rubrics[0]->status)->toBe(RubricStatus::Published)
        ->and($rubrics[1]->version)->toBe(2)
        ->and($rubrics[1]->min_reviewers)->toBe(3)
        ->and($rubrics[1]->status)->toBe(RubricStatus::Draft);
});

it('CA-011-3: in the panel a draft is edited in place and the new version action copies a published rubric into a draft', function (): void {
    [$organization, $event, $organizer, $type] = rubricPanelScenario();
    $draft = storedRubric($organizer, $event, $type);
    rubricPanelSession($organization, $organizer, $event);

    Livewire::test(EditRubric::class, ['record' => $draft->id])
        ->fillForm(['min_reviewers' => 4])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(CurrentOrganization::run($organization, fn () => Rubric::query()->sole()->min_reviewers))->toBe(4);

    $published = app(PublishRubric::class)->handle($organizer, $draft->fresh());

    Livewire::withQueryParams(['from' => $published->id])
        ->test(CreateRubric::class)
        ->assertFormSet(['min_reviewers' => 4, 'submission_type_id' => $type->id])
        ->call('create')
        ->assertHasNoFormErrors();

    $versions = CurrentOrganization::run($organization, fn () => Rubric::query()->orderBy('version')->pluck('status', 'version')->all());

    expect($versions[1])->toBe(RubricStatus::Published)->and($versions[2])->toBe(RubricStatus::Draft);
});

it('CA-011-1: in the panel the list publishes a draft and shows the published state', function (): void {
    [$organization, $event, $organizer, $type] = rubricPanelScenario();
    $draft = storedRubric($organizer, $event, $type);
    rubricPanelSession($organization, $organizer, $event);

    Livewire::test(ManageEventRubrics::class, ['record' => $event->id])
        ->assertCanSeeTableRecords([$draft])
        ->callAction(TestAction::make('publish')->table($draft))
        ->assertHasNoActionErrors();

    expect(CurrentOrganization::run($organization, fn () => Rubric::query()->findOrFail($draft->id)->status))->toBe(RubricStatus::Published);
});

it('CA-011-2: in the panel publishing a rubric without a scale criterion is refused and stays a draft', function (): void {
    [$organization, $event, $organizer, $type] = rubricPanelScenario();
    $draft = app(SaveRubric::class)->handle($organizer, $event, rubricFormData($type, [
        'criteria' => [['label' => ['es' => 'Comentarios'], 'kind' => 'text', 'weight' => 1]],
    ]));
    rubricPanelSession($organization, $organizer, $event);

    Livewire::test(ManageEventRubrics::class, ['record' => $event->id])
        ->callAction(TestAction::make('publish')->table($draft))
        ->assertNotified(__('reviews.publish_needs_scale_criterion'));

    expect(CurrentOrganization::run($organization, fn () => Rubric::query()->findOrFail($draft->id)->status))->toBe(RubricStatus::Draft);
});

it('CA-011-4: the list shows only the rubrics of the event and an empty state when there are none', function (): void {
    [$organization, $event, $organizer, $type] = rubricPanelScenario();
    [, $otherEvent, $otherOrganizer, $otherType] = rubricPanelScenario();
    $foreign = storedRubric($otherOrganizer, $otherEvent, $otherType);
    rubricPanelSession($organization, $organizer, $event);

    Livewire::test(ManageEventRubrics::class, ['record' => $event->id])
        ->assertCanNotSeeTableRecords([$foreign])
        ->assertSee(__('rubrics.table.empty_heading'));

    $own = storedRubric($organizer, $event, $type);

    Livewire::test(ManageEventRubrics::class, ['record' => $event->id])
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$foreign]);
});

it('CA-011-4: a rubric, an event or a type of another organization answers 404 and a rubric of the own event answers 200', function (): void {
    [$organization, $event, $organizer, $type] = rubricPanelScenario();
    [, $otherEvent, $otherOrganizer, $otherType] = rubricPanelScenario();
    $own = storedRubric($organizer, $event, $type);
    $foreign = storedRubric($otherOrganizer, $otherEvent, $otherType);

    $edit = fn (Event $e, Rubric $r): string => RubricResource::getUrl('edit', ['event' => $e, 'record' => $r], panel: 'admin', tenant: $organization);

    // Control: la misma ruta con una rúbrica propia responde 200; así los 404 son por el aislamiento y no por una URL mal escrita.
    $this->actingAs($organizer)->get($edit($event, $own))->assertOk();
    $this->actingAs($organizer)->get($edit($event, $foreign))->assertNotFound();
    $this->actingAs($organizer)->get($edit($otherEvent, $foreign))->assertNotFound();
    $this->actingAs($organizer)->get(EventResource::getUrl('rubrics', ['record' => $otherEvent], panel: 'admin', tenant: $organization))->assertNotFound();
});

it('CA-011-4: a rubric of another event of the same organization answers 404 under this event', function (): void {
    [$organization, $event, $organizer, $type] = rubricPanelScenario();
    $otherEvent = Event::factory()->create(['organization_id' => $organization->id]);
    $otherType = SubmissionType::factory()->create(['organization_id' => $organization->id, 'event_id' => $otherEvent->id]);
    $inOther = storedRubric($organizer, $otherEvent, $otherType);

    $this->actingAs($organizer)
        ->get(RubricResource::getUrl('edit', ['event' => $event, 'record' => $inOther], panel: 'admin', tenant: $organization))
        ->assertNotFound();
});

it('CA-011-4: copying a rubric of another organization through the new version link answers 404', function (): void {
    [$organization, $event, $organizer, $type] = rubricPanelScenario();
    [, $otherEvent, $otherOrganizer, $otherType] = rubricPanelScenario();
    $own = storedRubric($organizer, $event, $type);
    $foreign = storedRubric($otherOrganizer, $otherEvent, $otherType);
    $create = RubricResource::getUrl('create', ['event' => $event], panel: 'admin', tenant: $organization);

    // Control: copiar una rúbrica propia abre el formulario; la ajena responde 404.
    $this->actingAs($organizer)->get("{$create}?from={$own->id}")->assertOk();
    $this->actingAs($organizer)->get("{$create}?from={$foreign->id}")->assertNotFound();
});

it('CA-011-1: who cannot manage rubrics cannot open the list or the editor', function (): void {
    [$organization, $event, , $type] = rubricPanelScenario();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $rubric = storedRubric($admin, $event, $type);
    $reviewer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);

    expect($this->actingAs($reviewer)->get(EventResource::getUrl('rubrics', ['record' => $event], panel: 'admin', tenant: $organization))->status())->toBeIn([403, 404])
        ->and($this->actingAs($reviewer)->get(RubricResource::getUrl('edit', ['event' => $event, 'record' => $rubric], panel: 'admin', tenant: $organization))->status())->toBeIn([403, 404])
        ->and($this->actingAs($reviewer)->get(RubricResource::getUrl('create', ['event' => $event], panel: 'admin', tenant: $organization))->status())->toBeIn([403, 404]);

    // Control: quien sí puede, entra.
    $this->actingAs($admin)->get(EventResource::getUrl('rubrics', ['record' => $event], panel: 'admin', tenant: $organization))->assertOk();
});

it('CA-011-21: turning on blind review shows the warning and lists the fields that identify the author', function (): void {
    [$organization, $event, $organizer, $type] = rubricPanelScenario();
    createForm($organizer, $event, [
        formField('short_text', ['identifiesAuthor' => true, 'label' => ['es' => 'Institucion de origen', 'en' => 'Home institution']]),
        formField('long_text', ['label' => ['es' => 'Resumen', 'en' => 'Abstract']]),
    ]);
    rubricPanelSession($organization, $organizer, $event);

    Livewire::test(CreateRubric::class)
        ->fillForm(['blind_review' => false])
        ->assertDontSee(__('rubrics.blind.warning'))
        ->fillForm(['blind_review' => true])
        ->assertSee(__('rubrics.blind.warning'))
        ->assertSee('Institucion de origen')
        ->assertDontSee('Resumen');
});

it('CA-011-21: with blind review on and no field marked, the warning says no field identifies the author', function (): void {
    [$organization, $event, $organizer, $type] = rubricPanelScenario();
    rubricPanelSession($organization, $organizer, $event);

    Livewire::test(CreateRubric::class)
        ->fillForm(['blind_review' => true])
        ->assertSee(__('rubrics.blind.warning'))
        ->assertSee(__('rubrics.blind.no_marked_fields'));
});

it('CA-011-21: saving a blind rubric stores the flag and notifies the same warning', function (): void {
    [$organization, $event, $organizer, $type] = rubricPanelScenario();
    rubricPanelSession($organization, $organizer, $event);

    Livewire::test(CreateRubric::class)
        ->fillForm(rubricFormData($type, ['blind_review' => true]))
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified(__('rubrics.blind.saved_warning'));

    expect(CurrentOrganization::run($organization, fn () => Rubric::query()->sole()->blind_review))->toBeTrue();
});
