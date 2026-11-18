<?php

declare(strict_types=1);

use App\Actions\Forms\PublishFormDefinition;
use App\Actions\Forms\RetireFormField;
use App\Actions\Forms\UpdateFormDefinition;
use App\Enums\FormDefinitionStatus;
use App\Enums\OrganizationRole;
use App\Exceptions\Domain\FieldHasAnswers;
use App\Exceptions\Domain\FormChangeNotAllowed;
use App\Exceptions\Domain\FormVersionConflict;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\FormDefinition;
use App\Models\Organization;
use App\Support\Forms\LockedFormDefinition;
use App\Support\Forms\SubmissionAnswerCounter;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);
require_once __DIR__.'/../../Support/form-helpers.php';

afterEach(function (): void {
    CurrentOrganization::forget();
});

/**
 * Doble de `SubmissionAnswerCounter` (plan 006): dice si hay respuestas y qué opciones se eligieron.
 *
 * @param  list<string>  $usedOptions
 */
function answersExist(bool $hasAnswers = true, array $usedOptions = []): void
{
    app()->instance(SubmissionAnswerCounter::class, new class($hasAnswers, $usedOptions) implements SubmissionAnswerCounter
    {
        /** @param  list<string>  $usedOptions */
        public function __construct(private bool $hasAnswers, private array $usedOptions) {}

        public function hasAnswers(FormDefinition $form): bool
        {
            return $this->hasAnswers;
        }

        public function optionIsUsed(FormDefinition $form, string $fieldKey, string $optionValue): bool
        {
            return in_array($optionValue, $this->usedOptions, true);
        }
    });
}

function callOf(Organization $organization, Event $event): CallForSubmissions
{
    return CurrentOrganization::run($organization, fn (): CallForSubmissions => CallForSubmissions::factory()->create([
        'organization_id' => $organization->id,
        'event_id' => $event->id,
    ]));
}

/** El formulario tal como está en la base, leído en su organización. */
function reloadedForm(Organization $organization, FormDefinition $form): FormDefinition
{
    return CurrentOrganization::run($organization, fn (): FormDefinition => FormDefinition::query()->findOrFail($form->id));
}

it('CA-005-16: a form without fields cannot be published', function (): void {
    [$organization, $event, $admin] = formScenario();
    $form = createForm($admin, $event, []);

    expect(validationErrorKeys(fn () => app(PublishFormDefinition::class)->handle($admin, $form, callOf($organization, $event))))->toBe(['fields'])
        ->and(reloadedForm($organization, $form)->status)->toBe(FormDefinitionStatus::Draft);
});

it('CA-005-16: a form with a valid field is published and tied to the call', function (): void {
    [$organization, $event, $admin] = formScenario();
    $form = createForm($admin, $event, [formField('short_text')]);
    $call = callOf($organization, $event);

    $published = app(PublishFormDefinition::class)->handle($admin, $form, $call);
    $storedCall = CurrentOrganization::run($organization, fn (): CallForSubmissions => CallForSubmissions::query()->findOrFail($call->id));
    $audit = Activity::query()->where('event', 'form_definition_published')->sole();

    expect($published->status)->toBe(FormDefinitionStatus::Published)
        ->and($published->published_at)->not->toBeNull()
        ->and($storedCall->form_definition_id)->toBe($form->id)
        ->and($audit->causer_id)->toBe($admin->id)
        ->and($audit->properties['call_id'])->toBe($call->id);
});

it('CA-005-16: publishing twice keeps the first publication date and audits once', function (): void {
    [$organization, $event, $admin] = formScenario();
    $form = createForm($admin, $event, [formField('short_text')]);
    $call = callOf($organization, $event);

    $first = app(PublishFormDefinition::class)->handle($admin, $form, $call);
    $second = app(PublishFormDefinition::class)->handle($admin, $form, $call);

    expect($second->published_at->equalTo($first->published_at))->toBeTrue()
        ->and(Activity::query()->where('event', 'form_definition_published')->count())->toBe(1);
});

it('CA-005-16: only who manages forms can publish', function (): void {
    [$organization, $event, $reviewer, $admin] = formScenario(OrganizationRole::Reviewer);
    $form = createForm($admin, $event, [formField('short_text')]);

    expect(fn () => app(PublishFormDefinition::class)->handle($reviewer, $form, callOf($organization, $event)))
        ->toThrow(AuthorizationException::class)
        ->and(reloadedForm($organization, $form)->status)->toBe(FormDefinitionStatus::Draft);
});

it('CA-005-17: a call of another event of the same organization is rejected', function (): void {
    [$organization, $event, $admin] = formScenario();
    $otherEvent = CurrentOrganization::run($organization, fn (): Event => Event::factory()->create(['organization_id' => $organization->id]));
    $form = createForm($admin, $event, [formField('short_text')]);

    expect(validationErrorKeys(fn () => app(PublishFormDefinition::class)->handle($admin, $form, callOf($organization, $otherEvent))))->toBe(['call'])
        ->and(reloadedForm($organization, $form)->status)->toBe(FormDefinitionStatus::Draft);
});

it('CA-005-17: a form and a call of different organizations are never tied (404)', function (): void {
    [$organizationA, $eventA, $adminA] = formScenario();
    [$organizationB, $eventB, $adminB] = formScenario();
    $formA = createForm($adminA, $eventA, [formField('short_text')]);
    $formB = createForm($adminB, $eventB, [formField('short_text')]);

    expect(fn () => app(PublishFormDefinition::class)->handle($adminA, $formA, callOf($organizationB, $eventB)))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(PublishFormDefinition::class)->handle($adminA, $formB, callOf($organizationA, $eventA)))
        ->toThrow(ModelNotFoundException::class)
        ->and(reloadedForm($organizationB, $formB)->status)->toBe(FormDefinitionStatus::Draft);
});

it('CA-005-18: with answers a field cannot be deleted, without answers it can', function (): void {
    [$organization, $event, $admin] = formScenario();
    $form = createForm($admin, $event, [formField('short_text'), formField('long_text')]);
    $keep = $form->fields[0];

    app(UpdateFormDefinition::class)->handle($admin, $form, ['name' => $form->name, 'fields' => [$keep]]);
    $form->refresh();
    expect($form->fields)->toHaveCount(1);

    $two = createForm($admin, $event, [formField('short_text'), formField('long_text')]);
    answersExist();

    expect(fn () => app(UpdateFormDefinition::class)->handle($admin, $two, ['name' => $two->name, 'fields' => [$two->fields[0]]]))
        ->toThrow(FieldHasAnswers::class, FieldHasAnswers::CODE)
        ->and(reloadedForm($organization, $two)->fields)->toHaveCount(2);
});

it('CA-005-19: with answers the type of a field cannot change but its label and help can', function (): void {
    [$organization, $event, $admin] = formScenario();
    $form = createForm($admin, $event, [formField('short_text')]);
    answersExist();

    $retyped = ['type' => 'long_text', 'config' => []] + $form->fields[0];
    expect(fn () => app(UpdateFormDefinition::class)->handle($admin, $form, ['name' => $form->name, 'fields' => [$retyped]]))
        ->toThrow(FormChangeNotAllowed::class, FormChangeNotAllowed::CODE);

    $relabeled = $form->fields[0];
    $relabeled['label']['es'] = 'Título de la propuesta';
    $relabeled['help']['es'] = 'Ayuda nueva';
    app(UpdateFormDefinition::class)->handle($admin, $form, ['name' => $form->name, 'fields' => [$relabeled]]);

    $stored = reloadedForm($organization, $form)->fields[0];

    expect($stored['label']['es'])->toBe('Título de la propuesta')
        ->and($stored['help']['es'])->toBe('Ayuda nueva')
        ->and($stored['type'])->toBe('short_text');
});

it('CA-005-20: with answers a new required field is rejected and a new optional one is accepted', function (): void {
    [$organization, $event, $admin] = formScenario();
    $form = createForm($admin, $event, [formField('short_text')]);
    answersExist();

    expect(fn () => app(UpdateFormDefinition::class)->handle($admin, $form, ['name' => $form->name, 'fields' => [$form->fields[0], formField('long_text', ['required' => true])]]))
        ->toThrow(FormChangeNotAllowed::class);

    app(UpdateFormDefinition::class)->handle($admin, $form, ['name' => $form->name, 'fields' => [$form->fields[0], formField('long_text', ['required' => false])]]);

    expect(reloadedForm($organization, $form)->fields)->toHaveCount(2);
});

it('CA-005-20: with answers an option can be added and only an unused option can be removed', function (): void {
    [$organization, $event, $admin] = formScenario();
    $form = createForm($admin, $event, [formField('single_choice')]);
    $field = $form->fields[0];
    [$first, $second] = array_column($field['config']['options'], 'value');
    answersExist(usedOptions: [$first]);

    $withThird = $field;
    $withThird['config']['options'][] = ['label' => ['es' => 'Tercera']];
    app(UpdateFormDefinition::class)->handle($admin, $form, ['name' => $form->name, 'fields' => [$withThird]]);
    $form = reloadedForm($organization, $form);
    expect($form->fields[0]['config']['options'])->toHaveCount(3);

    $withoutUsed = $form->fields[0];
    $withoutUsed['config']['options'] = array_values(array_filter($withoutUsed['config']['options'], fn (array $option): bool => $option['value'] !== $first));
    expect(fn () => app(UpdateFormDefinition::class)->handle($admin, $form, ['name' => $form->name, 'fields' => [$withoutUsed]]))
        ->toThrow(FormChangeNotAllowed::class);

    $withoutUnused = $form->fields[0];
    $withoutUnused['config']['options'] = array_values(array_filter($withoutUnused['config']['options'], fn (array $option): bool => $option['value'] !== $second));
    app(UpdateFormDefinition::class)->handle($admin, $form, ['name' => $form->name, 'fields' => [$withoutUnused]]);

    expect(array_column(reloadedForm($organization, $form)->fields[0]['config']['options'], 'value'))->not->toContain($second)->toContain($first);
});

it('CA-005-21: a retired field keeps its data, stops being required and is audited', function (): void {
    [$organization, $event, $admin] = formScenario();
    $form = createForm($admin, $event, [formField('short_text'), formField('long_text')]);
    $key = $form->fields[1]['key'];

    app(RetireFormField::class)->handle($admin, $form, $key);
    $stored = reloadedForm($organization, $form);
    $audit = Activity::query()->where('event', 'form_field_retired')->sole();

    expect($stored->fields)->toHaveCount(2)
        ->and($stored->fields[1]['retiredAt'])->not->toBeNull()
        ->and($stored->fields[1]['required'])->toBeFalse()
        ->and($stored->fields[1]['label']['es'])->toBe($form->fields[1]['label']['es'])
        ->and($stored->fields[0]['retiredAt'])->toBeNull()
        ->and($stored->version)->toBe(2)
        ->and($audit->properties['field_key'])->toBe($key);
});

it('CA-005-21: retiring twice changes nothing and an unknown key is rejected', function (): void {
    [$organization, $event, $admin] = formScenario();
    $form = createForm($admin, $event, [formField('short_text')]);
    $key = $form->fields[0]['key'];

    app(RetireFormField::class)->handle($admin, $form, $key);
    app(RetireFormField::class)->handle($admin, $form, $key);

    expect(reloadedForm($organization, $form)->version)->toBe(2)
        ->and(validationErrorKeys(fn () => app(RetireFormField::class)->handle($admin, $form, 'f_inexistente')))->toBe(['field']);
});

it('CA-005-21: only who manages forms can retire a field', function (): void {
    [$organization, $event, $reviewer, $admin] = formScenario(OrganizationRole::Reviewer);
    $form = createForm($admin, $event, [formField('short_text')]);

    expect(fn () => app(RetireFormField::class)->handle($reviewer, $form, $form->fields[0]['key']))
        ->toThrow(AuthorizationException::class)
        ->and(reloadedForm($organization, $form)->fields[0]['retiredAt'])->toBeNull();
});

it('CA-005-22: saving with an older version is rejected and does not overwrite the other change', function (): void {
    [$organization, $event, $admin] = formScenario();
    $form = createForm($admin, $event, [formField('short_text')]);
    $field = $form->fields[0];

    app(UpdateFormDefinition::class)->handle($admin, $form, ['name' => 'Cambio del primero', 'fields' => [$field], 'version' => 1]);

    expect(fn () => app(UpdateFormDefinition::class)->handle($admin, $form, ['name' => 'Cambio del segundo', 'fields' => [$field], 'version' => 1]))
        ->toThrow(FormVersionConflict::class, FormVersionConflict::CODE)
        ->and(reloadedForm($organization, $form)->name)->toBe('Cambio del primero');
});

it('CA-005-23: a change raises the version by one and saving without changes keeps it', function (): void {
    [$organization, $event, $admin] = formScenario();
    $form = createForm($admin, $event, [formField('single_choice')]);
    expect($form->version)->toBe(1);

    $same = reloadedForm($organization, $form);
    app(UpdateFormDefinition::class)->handle($admin, $same, ['name' => $same->name, 'fields' => $same->fields, 'version' => 1]);
    expect(reloadedForm($organization, $form)->version)->toBe(1);

    app(UpdateFormDefinition::class)->handle($admin, $same, ['name' => 'Otro nombre', 'fields' => $same->fields, 'version' => 1]);
    expect(reloadedForm($organization, $form)->version)->toBe(2);
});

it('CA-005-24: the definition is loaded with FOR UPDATE so an edit and a submission serialize', function (): void {
    [$organization, $event, $admin] = formScenario();
    $form = createForm($admin, $event, [formField('short_text')]);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    DB::transaction(fn () => CurrentOrganization::run($organization, fn () => app(LockedFormDefinition::class)->handle($form->id)));

    expect(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'from "form_definitions"') && str_contains($sql, 'for update')))->toBeTrue();
});
