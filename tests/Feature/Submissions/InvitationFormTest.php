<?php

declare(strict_types=1);

use App\Actions\Events\CreateEvent;
use App\Actions\Submissions\ResolveInvitationForm;
use App\Actions\Submissions\SuggestInvitationForm;
use App\Enums\CallStatus;
use App\Enums\FormDefinitionStatus;
use App\Exceptions\Domain\InvitationFormNotPublished;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\FormDefinition;
use App\Models\Organization;
use App\Models\SubmissionType;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Tenancy\Exceptions\MissingOrganizationContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);
require_once __DIR__.'/../../Support/form-helpers.php';

afterEach(function (): void {
    CurrentOrganization::forget();
});

function typeOf(Organization $organization, Event $event, bool $archived = false): SubmissionType
{
    return CurrentOrganization::run($organization, fn (): SubmissionType => SubmissionType::factory()
        ->when($archived, fn ($factory) => $factory->archived())
        ->create(['organization_id' => $organization->id, 'event_id' => $event->id]));
}

function formOf(Organization $organization, Event $event, FormDefinitionStatus $status = FormDefinitionStatus::Published): FormDefinition
{
    return CurrentOrganization::run($organization, fn (): FormDefinition => FormDefinition::factory()->create([
        'organization_id' => $organization->id,
        'event_id' => $event->id,
        'status' => $status,
        'published_at' => $status === FormDefinitionStatus::Published ? now() : null,
    ]));
}

function callWith(Organization $organization, Event $event, SubmissionType $type, ?FormDefinition $form, CallStatus $status, string $opensAt): CallForSubmissions
{
    return CurrentOrganization::run($organization, function () use ($organization, $event, $type, $form, $status, $opensAt): CallForSubmissions {
        $call = CallForSubmissions::factory()->create([
            'organization_id' => $organization->id,
            'event_id' => $event->id,
            'submission_type_id' => $type->id,
            'opens_at' => $opensAt,
            'closes_at' => Carbon::parse($opensAt)->addDays(30),
        ]);
        $call->forceFill(['status' => $status, 'form_definition_id' => $form?->id])->save();

        return $call;
    });
}

it('CA-018-4: suggests the form of the most recent call of that type', function (): void {
    [$organization, $event] = formScenario();
    $type = typeOf($organization, $event);
    $olderForm = formOf($organization, $event);
    $newerForm = formOf($organization, $event);
    callWith($organization, $event, $type, $olderForm, CallStatus::Closed, '2026-01-10 15:00:00');
    callWith($organization, $event, $type, $newerForm, CallStatus::Published, '2027-01-10 15:00:00');

    $suggested = CurrentOrganization::run($organization, fn () => app(SuggestInvitationForm::class)->handle($event, $type));

    expect($suggested?->id)->toBe($newerForm->id);
});

it('CA-018-4: suggests nothing when no call of that type has a published form', function (): void {
    [$organization, $event] = formScenario();
    $type = typeOf($organization, $event);
    callWith($organization, $event, $type, null, CallStatus::Published, '2027-01-10 15:00:00');
    callWith($organization, $event, $type, formOf($organization, $event, FormDefinitionStatus::Draft), CallStatus::Draft, '2027-02-10 15:00:00');
    $otherType = typeOf($organization, $event);
    callWith($organization, $event, $otherType, formOf($organization, $event), CallStatus::Published, '2027-03-10 15:00:00');

    $suggested = CurrentOrganization::run($organization, fn () => app(SuggestInvitationForm::class)->handle($event, $type));

    expect($suggested)->toBeNull();
});

it('CA-018-4: the organizer can pick a different published form of the same event', function (): void {
    [$organization, $event] = formScenario();
    $type = typeOf($organization, $event);
    $suggested = formOf($organization, $event);
    $chosen = formOf($organization, $event);
    callWith($organization, $event, $type, $suggested, CallStatus::Published, '2027-01-10 15:00:00');

    $target = CurrentOrganization::run($organization, fn () => app(ResolveInvitationForm::class)->handle($event, $type->id, $chosen->id));

    expect($target->form->id)->toBe($chosen->id)
        ->and($target->type->id)->toBe($type->id);
});

it('CA-018-5: an archived type is rejected', function (): void {
    [$organization, $event] = formScenario();
    $type = typeOf($organization, $event, archived: true);
    $form = formOf($organization, $event);

    expect(validationErrorKeys(fn () => CurrentOrganization::run($organization, fn () => app(ResolveInvitationForm::class)->handle($event, $type->id, $form->id))))
        ->toBe(['submission_type']);
});

it('CA-018-5: a type of another event of the same organization is rejected', function (): void {
    [$organization, $event, , $admin] = formScenario();
    $otherEvent = app(CreateEvent::class)->handle($admin, $organization, [
        'name' => ['es' => 'Otro congreso'], 'slug' => 'otro-congreso', 'type' => 'congress',
        'starts_at' => '2027-05-10 09:00', 'ends_at' => '2027-05-12 18:00', 'timezone' => 'America/Mexico_City',
        'locales' => ['es'], 'default_locale' => 'es', 'currency' => 'MXN',
    ]);
    $foreignType = typeOf($organization, $otherEvent);
    $form = formOf($organization, $event);

    expect(validationErrorKeys(fn () => CurrentOrganization::run($organization, fn () => app(ResolveInvitationForm::class)->handle($event, $foreignType->id, $form->id))))
        ->toBe(['submission_type']);
});

it('CA-018-5: a form of another event of the same organization is rejected', function (): void {
    [$organization, $event, , $admin] = formScenario();
    $otherEvent = app(CreateEvent::class)->handle($admin, $organization, [
        'name' => ['es' => 'Otro congreso'], 'slug' => 'otro-congreso-2', 'type' => 'congress',
        'starts_at' => '2027-05-10 09:00', 'ends_at' => '2027-05-12 18:00', 'timezone' => 'America/Mexico_City',
        'locales' => ['es'], 'default_locale' => 'es', 'currency' => 'MXN',
    ]);
    $type = typeOf($organization, $event);
    $foreignForm = formOf($organization, $otherEvent);

    expect(validationErrorKeys(fn () => CurrentOrganization::run($organization, fn () => app(ResolveInvitationForm::class)->handle($event, $type->id, $foreignForm->id))))
        ->toBe(['form_definition']);
});

it('CA-018-5: an unpublished form is rejected with FORM_NOT_PUBLISHED', function (): void {
    [$organization, $event] = formScenario();
    $type = typeOf($organization, $event);
    $draft = formOf($organization, $event, FormDefinitionStatus::Draft);

    $resolve = fn () => CurrentOrganization::run($organization, fn () => app(ResolveInvitationForm::class)->handle($event, $type->id, $draft->id));

    expect($resolve)->toThrow(InvitationFormNotPublished::class, InvitationFormNotPublished::CODE);
});

it('CA-018-5: a type or form of another organization answers as not found', function (): void {
    [$organization, $event] = formScenario();
    [$otherOrganization, $otherEvent] = formScenario();
    $ownType = typeOf($organization, $event);
    $ownForm = formOf($organization, $event);
    $foreignType = typeOf($otherOrganization, $otherEvent);
    $foreignForm = formOf($otherOrganization, $otherEvent);

    $withForeignType = fn () => CurrentOrganization::run($organization, fn () => app(ResolveInvitationForm::class)->handle($event, $foreignType->id, $ownForm->id));
    $withForeignForm = fn () => CurrentOrganization::run($organization, fn () => app(ResolveInvitationForm::class)->handle($event, $ownType->id, $foreignForm->id));

    expect($withForeignType)->toThrow(ModelNotFoundException::class)
        ->and($withForeignForm)->toThrow(ModelNotFoundException::class);
});

it('CA-018-5: without organization context the resolution fails closed', function (): void {
    [$organization, $event] = formScenario();
    $type = typeOf($organization, $event);
    $form = formOf($organization, $event);
    CurrentOrganization::forget();

    expect(fn () => app(ResolveInvitationForm::class)->handle($event, $type->id, $form->id))
        ->toThrow(MissingOrganizationContext::class);
});
