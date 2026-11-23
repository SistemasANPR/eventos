<?php

declare(strict_types=1);

use App\Actions\Forms\CreateFormDefinition;
use App\Actions\Forms\PublishFormDefinition;
use App\Actions\Forms\UpdateFormDefinition;
use App\Models\CallForSubmissions;
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
