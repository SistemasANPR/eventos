<?php

declare(strict_types=1);

use App\Actions\Calls\SeedDefaultSubmissionTypes;
use App\Actions\Forms\CreateFormFromTemplate;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Forms\Pages\CreateFormDefinition;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\Organization;
use App\Models\SubmissionType;
use App\Models\User;
use App\Support\Events\OfferedLocaleTexts;
use App\Support\Forms\FormTemplates;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Memberships;

/*
 * Plan 022 F1: los idiomas del evento guían el constructor de formularios, las plantillas y
 * los tipos de propuesta predeterminados. Su fuente única es `Event::offeredLocales()`.
 */
uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/form-helpers.php';

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/** @param  list<string>  $locales */
function setEventLocales(Event $event, array $locales, string $default = 'es'): Event
{
    $event->forceFill(['locales' => $locales, 'default_locale' => $default])->save();

    return $event->refresh();
}

/** Abre el alta de un formulario con un campo de opciones, para que se pinten todos los textos traducibles. */
function openFormBuilder(Event $event): Testable
{
    $organization = Organization::query()->findOrFail($event->organization_id);
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    Auth::login($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);
    withEventRoute($event);

    return Livewire::test(CreateFormDefinition::class)->fillForm([
        'name' => 'Formulario',
        'fields' => [['type' => 'single_choice', 'label' => ['es' => 'Eje'], 'config' => ['options' => [['label' => ['es' => 'A']]]]]],
    ]);
}

it('offers the languages of the event with the default first', function (): void {
    [, $event] = formScenario();
    $event = setEventLocales($event, ['es', 'en', 'pt'], 'pt');

    expect($event->offeredLocales())->toBe(['pt', 'es', 'en']);
});

it('CA-022-10: the effective languages of a call are those that remain in the event, or the default one', function (): void {
    [$organization, $event] = formScenario();
    $event = setEventLocales($event, ['es', 'en']);
    $call = (new CallForSubmissions)->forceFill(['locales' => ['en', 'pt']]);
    $none = (new CallForSubmissions)->forceFill(['locales' => ['pt']]);

    expect($event->effectiveLocalesForCall($call))->toBe(['en'])
        ->and($event->effectiveLocalesForCall($none))->toBe(['es']);
});

it('CA-022-1: in an event only in Spanish the form builder asks only for Spanish texts', function (): void {
    [, $event] = formScenario();
    $event = setEventLocales($event, ['es']);

    openFormBuilder($event)
        ->assertSeeHtml('.label.es"')
        ->assertSeeHtml('.help.es"')
        ->assertDontSeeHtml('.label.en"')
        ->assertDontSeeHtml('.label.pt"')
        ->assertDontSeeHtml('.help.en"')
        ->assertDontSeeHtml('.help.pt"');
});

it('CA-022-2: in an event in Spanish and English the form builder shows both and only the default is required', function (): void {
    [, $event] = formScenario();

    $builder = openFormBuilder($event);
    $builder->assertSeeHtml('.label.es"')->assertSeeHtml('.label.en"')->assertDontSeeHtml('.label.pt"');

    // Los campos de etiqueta de un campo del formulario (no los de las opciones, que van en `config.options`).
    $isRequired = static function (string $locale) use ($builder): bool {
        preg_match('/<input[^>]*fields\.[^.\s"]+\.label\.'.$locale.'"[^>]*>/', $builder->html(), $match);

        return str_contains($match[0] ?? '', ' required');
    };

    expect($isRequired('es'))->toBeTrue()
        ->and($isRequired('en'))->toBeFalse();
});

it('CA-022-5: a form created from a template in an event only in Spanish carries only Spanish texts', function (string $template): void {
    [, $event, $admin] = formScenario();
    $event = setEventLocales($event, ['es']);

    $form = app(CreateFormFromTemplate::class)->handle($admin, $event, $template);
    $texts = [];

    foreach ($form->fields as $field) {
        $texts[] = $field['label'];
        $texts[] = $field['help'] ?? [];

        foreach ($field['config']['options'] ?? [] as $option) {
            $texts[] = $option['label'];
        }
    }

    expect(collect($texts)->flatMap(fn (array $text): array => array_keys($text))->unique()->values()->all())->toBe(['es']);
})->with(FormTemplates::names());

it('CA-022-5: a template keeps the offered languages and uses the default one when a translation is missing', function (): void {
    [, $event] = formScenario();
    $event = setEventLocales($event, ['en', 'pt'], 'en');

    $definition = FormTemplates::definitionFor(FormTemplates::SESSION, $event);

    expect(array_keys($definition['name']))->toBe(['en', 'pt'])
        ->and(array_keys($definition['fields'][0]['label']))->toBe(['en', 'pt']);
});

it('CA-022-5: a text missing in an offered language falls back to the default language of the event', function (): void {
    [, $event] = formScenario();
    $event = setEventLocales($event, ['es', 'en', 'pt'], 'en');

    expect(OfferedLocaleTexts::for(['en' => 'Title', 'es' => 'Título'], $event))->toBe(['en' => 'Title', 'es' => 'Título', 'pt' => 'Title']);
});

it('CA-022-5: the default submission types of an event only in Spanish are only in Spanish', function (): void {
    [$organization, $event] = formScenario();
    CurrentOrganization::set($organization);
    $event = setEventLocales($event, ['es']);
    SubmissionType::query()->where('event_id', $event->id)->delete();

    app(SeedDefaultSubmissionTypes::class)->handle($event);

    $types = SubmissionType::query()->where('event_id', $event->id)->orderBy('position')->get();

    expect($types)->toHaveCount(4)
        ->and($types->map(fn (SubmissionType $type): array => array_keys($type->getTranslations('name')))->unique()->all())->toBe([['es']]);
});
