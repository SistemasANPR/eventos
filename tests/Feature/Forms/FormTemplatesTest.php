<?php

declare(strict_types=1);

use App\Actions\Forms\CreateFormFromTemplate;
use App\Actions\Forms\DuplicateFormDefinition;
use App\Actions\Forms\PublishFormDefinition;
use App\Actions\Forms\RetireFormField;
use App\Actions\Forms\UpdateFormDefinition;
use App\Enums\FormDefinitionStatus;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Forms\Pages\ListFormDefinitions;
use App\Models\CallForSubmissions;
use App\Models\FormDefinition;
use App\Models\Organization;
use App\Support\Forms\FormTemplates;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

uses(RefreshDatabase::class);
require_once __DIR__.'/../../Support/form-helpers.php';

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

function formInOrganization(Organization $organization, string $id): FormDefinition
{
    return CurrentOrganization::run($organization, fn (): FormDefinition => FormDefinition::query()->findOrFail($id));
}

it('CA-005-35: every system template builds a valid independent draft form in the event', function (string $template): void {
    [$organization, $event, $admin] = formScenario();

    $form = app(CreateFormFromTemplate::class)->handle($admin, $event, $template);

    expect($form->status)->toBe(FormDefinitionStatus::Draft)
        ->and($form->event_id)->toBe($event->id)
        ->and($form->fields)->not->toBeEmpty()
        ->and(array_column($form->fields, 'key'))->each->toMatch('/^f_[a-z0-9]{6,}$/')
        ->and($form->name)->toBe(FormTemplates::definition($template)['name']['es']);
})->with(FormTemplates::names());

it('CA-005-35: the template fields are written in the three platform languages', function (string $template): void {
    $missing = [];

    foreach (FormTemplates::definition($template)['fields'] as $index => $field) {
        foreach (['es', 'en', 'pt'] as $locale) {
            if (! isset($field['label'][$locale])) {
                $missing[] = "{$index}:{$locale}";
            }
        }
    }

    expect($missing)->toBe([]);
})->with(FormTemplates::names());

it('CA-005-35: editing the copy does not change the template, and two copies do not share keys', function (): void {
    [$organization, $event, $admin] = formScenario();
    $before = FormTemplates::definition(FormTemplates::SESSION);

    $first = app(CreateFormFromTemplate::class)->handle($admin, $event, FormTemplates::SESSION);
    $second = app(CreateFormFromTemplate::class)->handle($admin, $event, FormTemplates::SESSION);
    $edited = $first->fields;
    $edited[0]['label']['es'] = 'Título editado';
    app(UpdateFormDefinition::class)->handle($admin, $first, ['name' => 'Mi formulario', 'fields' => $edited]);

    expect(FormTemplates::definition(FormTemplates::SESSION))->toBe($before)
        ->and(array_intersect(array_column($first->fields, 'key'), array_column($second->fields, 'key')))->toBe([])
        ->and(formInOrganization($organization, $second->id)->fields[0]['label']['es'])->toBe('Título');
});

it('CA-005-35: an event whose base language is Portuguese gets the Portuguese name', function (): void {
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::run($organization, fn () => $event->forceFill(['locales' => ['pt', 'es'], 'default_locale' => 'pt'])->save());

    $form = app(CreateFormFromTemplate::class)->handle($admin, $event->refresh(), FormTemplates::POSTER);

    expect($form->name)->toBe('Formulário de pôster');
});

it('CA-005-35: an unknown template is rejected and nothing is created', function (): void {
    [$organization, $event, $admin] = formScenario();

    expect(validationErrorKeys(fn () => app(CreateFormFromTemplate::class)->handle($admin, $event, 'inexistente')))->toBe(['template'])
        ->and(FormDefinition::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('CA-005-37: who does not manage forms cannot create from a template', function (): void {
    [, $event, $reviewer] = formScenario(OrganizationRole::Reviewer);

    expect(fn () => app(CreateFormFromTemplate::class)->handle($reviewer, $event, FormTemplates::SESSION))
        ->toThrow(AuthorizationException::class)
        ->and(FormDefinition::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('CA-005-36: duplicating gives a draft copy with the same fields and options but new keys, no call and no history', function (): void {
    [$organization, $event, $admin] = formScenario();
    $source = createForm($admin, $event, [formField('short_text'), formField('single_choice')], 'Original');
    $call = CurrentOrganization::run($organization, fn () => CallForSubmissions::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id]));
    app(PublishFormDefinition::class)->handle($admin, $source, $call);

    $copy = app(DuplicateFormDefinition::class)->handle($admin, formInOrganization($organization, $source->id));
    $storedCall = CurrentOrganization::run($organization, fn () => CallForSubmissions::query()->findOrFail($call->id));
    $labels = fn (FormDefinition $form): array => array_column(array_column($form->fields, 'label'), 'es');
    $optionLabels = fn (FormDefinition $form): array => array_column(array_column($form->fields[1]['config']['options'], 'label'), 'es');

    expect($copy->id)->not->toBe($source->id)
        ->and($copy->status)->toBe(FormDefinitionStatus::Draft)
        ->and($copy->published_at)->toBeNull()
        ->and($copy->version)->toBe(1)
        ->and($copy->name)->toBe('Original (copia)')
        ->and($labels($copy))->toBe($labels($source))
        ->and($optionLabels($copy))->toBe($optionLabels($source))
        ->and(array_intersect(array_column($copy->fields, 'key'), array_column($source->fields, 'key')))->toBe([])
        ->and(array_intersect(array_column($copy->fields[1]['config']['options'], 'value'), array_column($source->fields[1]['config']['options'], 'value')))->toBe([])
        ->and($storedCall->form_definition_id)->toBe($source->id);
});

it('CA-005-36: the copy leaves out retired fields', function (): void {
    [$organization, $event, $admin] = formScenario();
    $source = createForm($admin, $event, [formField('short_text'), formField('long_text')]);
    app(RetireFormField::class)->handle($admin, $source, $source->fields[1]['key']);

    $copy = app(DuplicateFormDefinition::class)->handle($admin, formInOrganization($organization, $source->id));

    expect($copy->fields)->toHaveCount(1)
        ->and($copy->fields[0]['type'])->toBe('short_text');
});

it('CA-005-37: duplicating the form of another organization answers 404 and creates nothing', function (): void {
    [, , $adminA] = formScenario();
    [$organizationB, $eventB, $adminB] = formScenario();
    $foreign = createForm($adminB, $eventB, [formField('short_text')]);
    $before = FormDefinition::query()->withoutGlobalScopes()->count();

    expect(fn () => app(DuplicateFormDefinition::class)->handle($adminA, formInOrganization($organizationB, $foreign->id)))
        ->toThrow(ModelNotFoundException::class)
        ->and(FormDefinition::query()->withoutGlobalScopes()->count())->toBe($before);
});

it('CA-005-37: a member without forms.manage cannot duplicate', function (): void {
    [$organization, $event, $reviewer, $admin] = formScenario(OrganizationRole::Reviewer);
    $source = createForm($admin, $event, [formField('short_text')]);

    expect(fn () => app(DuplicateFormDefinition::class)->handle($reviewer, formInOrganization($organization, $source->id)))
        ->toThrow(AuthorizationException::class);
});

it('CA-005-35: in the panel an admin creates a form from a template choosing the event', function (): void {
    [$organization, $event, $admin] = formScenario();
    Auth::login($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    Livewire::test(ListFormDefinitions::class)
        ->callAction('fromTemplate', ['template' => 'panel', 'event_id' => $event->id])
        ->assertHasNoActionErrors();

    $form = CurrentOrganization::run($organization, fn (): FormDefinition => FormDefinition::query()->sole());

    expect($form->name)->toBe('Formulario de panel')
        ->and($form->status)->toBe(FormDefinitionStatus::Draft);
});

it('CA-005-36: in the panel the table action duplicates a form into an independent draft', function (): void {
    [$organization, $event, $admin] = formScenario();
    $source = createForm($admin, $event, [formField('short_text')], 'Base');
    Auth::login($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    Livewire::test(ListFormDefinitions::class)
        ->callTableAction('duplicate', formInOrganization($organization, $source->id))
        ->assertHasNoTableActionErrors();

    $names = CurrentOrganization::run($organization, fn (): array => FormDefinition::query()->orderBy('name')->pluck('name')->all());

    expect($names)->toBe(['Base', 'Base (copia)']);
});
