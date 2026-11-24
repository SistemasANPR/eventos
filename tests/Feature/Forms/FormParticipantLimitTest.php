<?php

declare(strict_types=1);

use App\Actions\Forms\CreateFormDefinition;
use App\Actions\Forms\PublishFormDefinition;
use App\Actions\Forms\UpdateFormDefinition;
use App\Models\CallForSubmissions;
use App\Support\Forms\FormSettings;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);
require_once __DIR__.'/../../Support/form-helpers.php';

afterEach(function (): void {
    CurrentOrganization::forget();
});

it('a form without a limit leaves the participant limit of the call alone', function (): void {
    [$organization, $event, $admin] = formScenario();
    $call = CallForSubmissions::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id, 'max_participants' => 3]);

    assignPublishedForm($admin, $call);

    expect($call->fresh()->max_participants)->toBe(3);
});

it('the participant limit of the form is applied to the call when the form is published', function (): void {
    [$organization, $event, $admin] = formScenario();
    $call = CallForSubmissions::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id]);
    $form = app(CreateFormDefinition::class)->handle($admin, $event, ['name' => 'Formulario', 'fields' => [formField('short_text')], 'max_participants' => 2]);

    expect($form->max_participants)->toBe(2);

    app(PublishFormDefinition::class)->handle($admin, $form, $call);

    expect($call->fresh()->max_participants)->toBe(2);
});

it('editing the limit of a published form updates its call, and lowers the minimum if needed', function (): void {
    [$organization, $event, $admin] = formScenario();
    $call = CallForSubmissions::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id, 'min_participants' => 3, 'max_participants' => 5]);
    $form = assignPublishedForm($admin, $call);

    app(UpdateFormDefinition::class)->handle($admin, $form, ['name' => $form->name, 'fields' => $form->fields, 'max_participants' => 2]);

    $call = $call->fresh();

    expect($call->max_participants)->toBe(2)
        ->and($call->min_participants)->toBe(2);
});

it('the participant limit must be a whole number from 1 to 20', function (mixed $value): void {
    [, $event, $admin] = formScenario();

    expect(fn () => app(CreateFormDefinition::class)->handle($admin, $event, ['name' => 'F', 'fields' => [formField('short_text')], 'max_participants' => $value]))
        ->toThrow(ValidationException::class);
})->with([0, 21, 'muchos', 2.5]);

it('a form without settings behaves as always: everything shown, the usual required ones and participants first', function (): void {
    $fields = FormSettings::participantFields(null);

    expect($fields['first_name'])->toBe(['enabled' => true, 'required' => true])
        ->and($fields['company'])->toBe(['enabled' => true, 'required' => true])
        ->and($fields['second_last_name'])->toBe(['enabled' => true, 'required' => false])
        ->and($fields['photo'])->toBe(['enabled' => true, 'required' => false])
        ->and(FormSettings::position(null))->toBe('top')
        ->and(FormSettings::normalize(['participants_position' => 'top']))->toBeNull();
});

it('the name, paternal last name, email and country can never be turned off or made optional', function (): void {
    $fields = FormSettings::participantFields(['participant_fields' => [
        'first_name' => ['enabled' => false, 'required' => false],
        'email' => ['enabled' => true, 'required' => false],
        'bio' => ['enabled' => false, 'required' => true],
    ]]);

    expect($fields['first_name'])->toBe(['enabled' => true, 'required' => true])
        ->and($fields['email'])->toBe(['enabled' => true, 'required' => true])
        ->and($fields['bio'])->toBe(['enabled' => false, 'required' => false]);
});

it('an admin saves the settings of a form and a change bumps its version', function (): void {
    [, $event, $admin] = formScenario();
    $form = app(CreateFormDefinition::class)->handle($admin, $event, ['name' => 'Formulario', 'fields' => [formField('short_text')]]);

    expect($form->settings)->toBeNull();

    $updated = app(UpdateFormDefinition::class)->handle($admin, $form, [
        'name' => $form->name,
        'fields' => $form->fields,
        'settings' => [
            'participants_position' => 'bottom',
            'title' => ['es' => 'Registro de póster'],
            'extra_section_title' => ['es' => 'Datos del proyecto'],
            'participant_fields' => ['company' => ['enabled' => false], 'bio' => ['enabled' => true, 'required' => false]],
        ],
    ]);

    expect($updated->version)->toBe($form->version + 1)
        ->and($updated->settings['participants_position'])->toBe('bottom')
        ->and($updated->settings['title'])->toBe(['es' => 'Registro de póster'])
        ->and($updated->settings['extra_section_title'])->toBe(['es' => 'Datos del proyecto'])
        ->and($updated->settings['participant_fields']['company']['enabled'])->toBeFalse()
        ->and($updated->settings['participant_fields']['bio'])->toBe(['enabled' => true, 'required' => false]);

    // Guardar lo mismo no cambia la versión.
    $same = app(UpdateFormDefinition::class)->handle($admin, $updated, ['name' => $updated->name, 'fields' => $updated->fields, 'settings' => $updated->settings]);

    expect($same->version)->toBe($updated->version);
});

it('the participant rules follow the form: off is refused, required is required, optional may be empty', function (): void {
    $settings = ['participant_fields' => [
        'company' => ['enabled' => false],
        'bio' => ['enabled' => true, 'required' => false],
        'state' => ['enabled' => true, 'required' => true],
    ]];
    $rules = FormSettings::participantRules($settings);

    expect($rules['participants.*.company'])->toBe(['prohibited'])
        ->and($rules['participants.*.bio'])->toContain('nullable')
        ->and($rules['participants.*.state'])->toContain('required');
});
