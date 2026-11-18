<?php

declare(strict_types=1);

use App\Actions\Calls\PublishCall;
use App\Actions\Calls\UnassignFormFromCall;
use App\Actions\Forms\PublishFormDefinition;
use App\Actions\Privacy\CreatePrivacyNoticeDraft;
use App\Actions\Privacy\PublishPrivacyNotice;
use App\Enums\CallStatus;
use App\Enums\OrganizationRole;
use App\Exceptions\Domain\PrivacyNoticeMissing;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\FormDefinition;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../../Support/form-helpers.php';

/*
 * Plan 022 F1: la convocatoria exige formulario para publicarse y se le quita solo en borrador.
 * Un solo rol por prueba: la caché de permisos de Spatie es compartida entre usuarios.
 */

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
});

/** Convocatoria del evento en el estado indicado (por la factoría, sin pasar por las Actions bajo prueba). */
function draftCallOf(Organization $organization, Event $event, CallStatus $status = CallStatus::Draft): CallForSubmissions
{
    return CurrentOrganization::run($organization, fn (): CallForSubmissions => CallForSubmissions::factory()->create([
        'organization_id' => $organization->id,
        'event_id' => $event->id,
        'locales' => ['es'],
        'status' => $status,
    ]));
}

function callFormId(Organization $organization, CallForSubmissions $call): ?string
{
    return CurrentOrganization::run($organization, fn (): ?string => CallForSubmissions::query()->findOrFail($call->id)->form_definition_id);
}

it('CA-022-7: a draft call without a form is refused with the form key and stays in draft', function (): void {
    [$organization, $event, , $admin] = formScenario();
    app(PublishPrivacyNotice::class)->handle($admin, app(CreatePrivacyNoticeDraft::class)->handle($admin, $event, ['es' => '<p>Aviso</p>']));
    $call = draftCallOf($organization, $event);

    $keys = validationErrorKeys(fn () => app(PublishCall::class)->handle($admin, $call));

    expect($keys)->toBe(['form'])
        ->and($call->fresh()->status)->toBe(CallStatus::Draft)
        ->and(Activity::query()->where('event', 'call_published')->count())->toBe(0);
});

it('CA-022-7: the missing form is reported before the privacy notice', function (): void {
    [$organization, $event, , $admin] = formScenario();
    $call = draftCallOf($organization, $event);

    expect(validationErrorKeys(fn () => app(PublishCall::class)->handle($admin, $call)))->toBe(['form']);
});

it('CA-022-7: with a form assigned the privacy notice is still required', function (): void {
    [$organization, $event, , $admin] = formScenario();
    $call = draftCallOf($organization, $event);
    assignPublishedForm($admin, $call);

    expect(fn () => app(PublishCall::class)->handle($admin, $call->fresh()))->toThrow(PrivacyNoticeMissing::class);
});

it('CA-022-7: a call with a form and a published notice is published', function (): void {
    [$organization, $event, , $admin] = formScenario();
    app(PublishPrivacyNotice::class)->handle($admin, app(CreatePrivacyNoticeDraft::class)->handle($admin, $event, ['es' => '<p>Aviso</p>']));
    $call = draftCallOf($organization, $event);
    assignPublishedForm($admin, $call);

    expect(app(PublishCall::class)->handle($admin, $call->fresh())->status)->toBe(CallStatus::Published);
});

it('CA-022-8: who has calls.manage unassigns the form of a draft call and it is audited', function (OrganizationRole $role): void {
    [$organization, $event, $actor, $admin] = formScenario($role);
    $call = draftCallOf($organization, $event);
    $form = assignPublishedForm($admin, $call);

    $result = app(UnassignFormFromCall::class)->handle($actor, $call->fresh());
    $audit = Activity::query()->where('event', 'call_form_unassigned')->sole();

    expect($result->form_definition_id)->toBeNull()
        ->and(callFormId($organization, $call))->toBeNull()
        ->and($audit->causer_id)->toBe($actor->id)
        ->and($audit->properties['organization_id'])->toBe($organization->id)
        ->and($audit->properties['previous_form_definition_id'])->toBe($form->id);
})->with([
    'org admin' => [OrganizationRole::OrgAdmin],
    'organizer' => [OrganizationRole::Organizer],
]);

it('CA-022-8: without calls.manage the form stays assigned and nothing is audited', function (): void {
    [$organization, $event, $reviewer, $admin] = formScenario(OrganizationRole::Reviewer);
    $call = draftCallOf($organization, $event);
    $form = assignPublishedForm($admin, $call);

    expect(fn () => app(UnassignFormFromCall::class)->handle($reviewer, $call->fresh()))->toThrow(AuthorizationException::class)
        ->and(callFormId($organization, $call))->toBe($form->id)
        ->and(Activity::query()->where('event', 'call_form_unassigned')->count())->toBe(0);
});

it('CA-022-8: unassigning a call that has no form changes nothing and audits nothing', function (): void {
    [$organization, $event, , $admin] = formScenario();
    $call = draftCallOf($organization, $event);

    $result = app(UnassignFormFromCall::class)->handle($admin, $call);

    expect($result->form_definition_id)->toBeNull()
        ->and(Activity::query()->where('event', 'call_form_unassigned')->count())->toBe(0);
});

it('CA-022-8: an admin of A cannot unassign the form of a call of B', function (): void {
    [, , , $adminA] = formScenario();
    [$organizationB, $eventB, , $adminB] = formScenario();
    $callB = draftCallOf($organizationB, $eventB);
    $formB = assignPublishedForm($adminB, $callB);

    expect(fn () => app(UnassignFormFromCall::class)->handle($adminA, $callB->fresh()))->toThrow(AuthorizationException::class)
        ->and(callFormId($organizationB, $callB))->toBe($formB->id);
});

it('CA-022-9: a published, paused or closed call refuses to unassign its form', function (CallStatus $status): void {
    [$organization, $event, , $admin] = formScenario();
    $call = draftCallOf($organization, $event);
    $form = assignPublishedForm($admin, $call);
    CurrentOrganization::run($organization, fn () => CallForSubmissions::query()->findOrFail($call->id)->forceFill(['status' => $status])->save());

    expect(validationErrorKeys(fn () => app(UnassignFormFromCall::class)->handle($admin, $call->fresh())))->toBe(['status'])
        ->and(callFormId($organization, $call))->toBe($form->id)
        ->and(Activity::query()->where('event', 'call_form_unassigned')->count())->toBe(0);
})->with([
    'published' => [CallStatus::Published],
    'paused' => [CallStatus::Paused],
    'closed' => [CallStatus::Closed],
]);

it('CA-022-5: a form of another event of the same organization is refused when forced onto a call', function (): void {
    [$organization, $event, , $admin] = formScenario();
    $otherEvent = CurrentOrganization::run($organization, fn (): Event => Event::factory()->create(['organization_id' => $organization->id]));
    $call = draftCallOf($organization, $otherEvent);
    $form = createForm($admin, $event, [formField('short_text')]);

    expect(validationErrorKeys(fn () => app(PublishFormDefinition::class)->handle($admin, $form, $call)))->toBe(['call'])
        ->and(callFormId($organization, $call))->toBeNull();
});

it('CA-022-5: a form of another organization answers as missing (404)', function (): void {
    [$organizationA, $eventA, , $adminA] = formScenario();
    [, $eventB, , $adminB] = formScenario();
    $callA = draftCallOf($organizationA, $eventA);
    $formB = createForm($adminB, $eventB, [formField('short_text')]);

    expect(fn () => app(PublishFormDefinition::class)->handle($adminA, $formB, $callA))->toThrow(ModelNotFoundException::class)
        ->and(callFormId($organizationA, $callA))->toBeNull()
        ->and(FormDefinition::query()->withoutGlobalScopes()->findOrFail($formB->id)->status->value)->toBe('draft');
});

it('CA-022-6: without forms.manage the Action denies assigning a form', function (): void {
    [$organization, $event, $reviewer, $admin] = formScenario(OrganizationRole::Reviewer);
    $call = draftCallOf($organization, $event);
    $form = createForm($admin, $event, [formField('short_text')]);

    expect(fn () => app(PublishFormDefinition::class)->handle($reviewer, $form, $call))->toThrow(AuthorizationException::class)
        ->and(callFormId($organization, $call))->toBeNull();
});
