<?php

declare(strict_types=1);

use App\Actions\Calls\ChangeCallStatus;
use App\Actions\Calls\CreateCallForSubmissions;
use App\Actions\Calls\PublishCall;
use App\Actions\Privacy\CreatePrivacyNoticeDraft;
use App\Actions\Privacy\PublishPrivacyNotice;
use App\Enums\CallStatus;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Resources\Events\RelationManagers\CallsRelationManager;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Memberships;
use Tests\Support\SubmissionTypes;

require_once __DIR__.'/../../Support/form-helpers.php';

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/** @return array{0: Organization, 1: Event, 2: User} */
function callPanelScenario(OrganizationRole $role = OrganizationRole::OrgAdmin): array
{
    $organization = Memberships::organization();
    $user = Memberships::attach(User::factory()->create(), $organization, $role);
    $event = Event::factory()->create([
        'organization_id' => $organization->id,
        'timezone' => 'America/Mexico_City',
        'locales' => ['es', 'en'],
        'default_locale' => 'es',
    ]);

    Auth::login($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    return [$organization, $event, $user];
}

/** @return Testable<CallsRelationManager> */
function callManager(Event $event): Testable
{
    return Livewire::test(CallsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => ViewEvent::class]);
}

/** @return array<string, mixed> */
function panelCallData(Event $event): array
{
    return [
        'submission_type_id' => SubmissionTypes::for($event)->id,
        'title' => ['es' => 'Convocatoria de sesiones', 'en' => 'Call for sessions'],
        'locales' => ['es', 'en'],
        'opens_at' => '2027-01-10 09:00',
        'closes_at' => '2027-02-15 23:00',
    ];
}

function publishNoticeInBothLanguages(Event $event, User $admin): void
{
    app(PublishPrivacyNotice::class)->handle($admin, app(CreatePrivacyNoticeDraft::class)->handle($admin, $event, ['es' => '<p>Aviso</p>', 'en' => '<p>Notice</p>']));
}

it('CA-004-1: an admin sees the calls of the event in the panel', function (): void {
    [$organization, $event, $admin] = callPanelScenario();
    $call = app(CreateCallForSubmissions::class)->handle($admin, $event, panelCallData($event));
    CurrentOrganization::set($organization);

    callManager($event)->assertCanSeeTableRecords([$call]);
});

it('CA-004-5: a reviewer does not see the calls manager at all', function (): void {
    [, $event] = callPanelScenario(OrganizationRole::Reviewer);

    expect(CallsRelationManager::canViewForRecord($event, ViewEvent::class))->toBeFalse();
});

it('CA-004-5: an organizer, who has calls.manage, sees the calls manager', function (): void {
    [, $event] = callPanelScenario(OrganizationRole::Organizer);

    expect(CallsRelationManager::canViewForRecord($event, ViewEvent::class))->toBeTrue();
});

it('CA-004-1: an admin creates a draft call from the panel, with the dates in UTC', function (): void {
    [$organization, $event] = callPanelScenario();

    callManager($event)
        ->callAction(TestAction::make('createCall')->table(), panelCallData($event))
        ->assertHasNoFormErrors();

    CurrentOrganization::set($organization);
    $call = CallForSubmissions::query()->sole();

    expect($call->status)->toBe(CallStatus::Draft)
        ->and($call->opens_at->utc()->format('Y-m-d H:i'))->toBe('2027-01-10 15:00')
        ->and($call->getTranslation('title', 'en'))->toBe('Call for sessions');
});

it('CA-004-2: the panel saves nothing when the dates are invalid', function (): void {
    [$organization, $event] = callPanelScenario();

    callManager($event)->callAction(TestAction::make('createCall')->table(), [...panelCallData($event), 'closes_at' => '2027-01-09 09:00']);

    CurrentOrganization::set($organization);

    expect(CallForSubmissions::query()->count())->toBe(0);
});

it('CA-004-9: an admin publishes a call from the panel when the notice is published', function (): void {
    [$organization, $event, $admin] = callPanelScenario();
    publishNoticeInBothLanguages($event, $admin);
    $call = app(CreateCallForSubmissions::class)->handle($admin, $event, panelCallData($event));
    assignPublishedForm($admin, $call);
    CurrentOrganization::set($organization);

    callManager($event)->callAction(TestAction::make('publish')->table($call));

    expect($call->fresh()->status)->toBe(CallStatus::Published);
});

it('CA-004-10: publishing without the privacy notice leaves the call in draft', function (): void {
    [$organization, $event, $admin] = callPanelScenario();
    $call = app(CreateCallForSubmissions::class)->handle($admin, $event, panelCallData($event));
    assignPublishedForm($admin, $call);
    CurrentOrganization::set($organization);

    callManager($event)->callAction(TestAction::make('publish')->table($call));

    expect($call->fresh()->status)->toBe(CallStatus::Draft);
});

it('CA-004-9: a published call offers no publish action again', function (): void {
    [$organization, $event, $admin] = callPanelScenario();
    publishNoticeInBothLanguages($event, $admin);
    $call = app(CreateCallForSubmissions::class)->handle($admin, $event, panelCallData($event));
    assignPublishedForm($admin, $call);
    $published = app(PublishCall::class)->handle($admin, $call);
    CurrentOrganization::set($organization);

    callManager($event)->assertActionHidden(TestAction::make('publish')->table($published));
});

it('CA-004-1: the edit form shows the dates in the local time of the event, not in UTC', function (): void {
    [$organization, $event, $admin] = callPanelScenario();
    $call = app(CreateCallForSubmissions::class)->handle($admin, $event, panelCallData($event));
    CurrentOrganization::set($organization);

    $manager = callManager($event)->mountAction(TestAction::make('editCall')->table($call));

    expect($manager->get('mountedActions.0.data.opens_at'))->toBe('2027-01-10 09:00')
        ->and($manager->get('mountedActions.0.data.closes_at'))->toBe('2027-02-15 23:00');
});

it('CA-004-11: an admin edits a call from the panel and the new close is stored in UTC', function (): void {
    [$organization, $event, $admin] = callPanelScenario();
    $call = app(CreateCallForSubmissions::class)->handle($admin, $event, panelCallData($event));
    CurrentOrganization::set($organization);

    callManager($event)->callAction(TestAction::make('editCall')->table($call), [...panelCallData($event), 'closes_at' => '2027-02-20 23:00']);

    expect($call->fresh()->closes_at->utc()->format('Y-m-d H:i'))->toBe('2027-02-21 05:00');
});

it('CA-004-6: the panel offers no way to delete a call', function (): void {
    [$organization, $event, $admin] = callPanelScenario();
    $call = app(CreateCallForSubmissions::class)->handle($admin, $event, panelCallData($event));
    CurrentOrganization::set($organization);

    callManager($event)
        ->assertActionDoesNotExist(TestAction::make('delete')->table($call))
        ->assertActionDoesNotExist(TestAction::make('forceDelete')->table($call));
});

function callInStatus(Event $event, User $admin, CallStatus $status): CallForSubmissions
{
    $call = app(CreateCallForSubmissions::class)->handle($admin, $event, panelCallData($event));

    if ($status === CallStatus::Draft) {
        return $call;
    }

    publishNoticeInBothLanguages($event, $admin);
    assignPublishedForm($admin, $call);
    $call = app(PublishCall::class)->handle($admin, $call);

    return $status === CallStatus::Published ? $call : app(ChangeCallStatus::class)->handle($admin, $call, $status);
}

it('CA-004-37: a published, paused or closed call offers its public page, opened in a new tab, with the URL of its own organization, event and call', function (CallStatus $status): void {
    [$organization, $event, $admin] = callPanelScenario();
    $call = callInStatus($event, $admin, $status);
    CurrentOrganization::set($organization);

    $publicUrl = route('calls.show', ['organization' => $organization->slug, 'event' => $event->slug, 'call' => $call->slug]);

    callManager($event)
        ->assertActionVisible(TestAction::make('viewPublicPage')->table($call))
        ->assertActionHasUrl(TestAction::make('viewPublicPage')->table($call), $publicUrl)
        ->assertActionShouldOpenUrlInNewTab(TestAction::make('viewPublicPage')->table($call))
        ->assertActionVisible(TestAction::make('copyPublicLink')->table($call));
})->with([
    'published' => [CallStatus::Published],
    'paused' => [CallStatus::Paused],
    'closed' => [CallStatus::Closed],
]);

it('CA-004-38: a draft call offers neither the public page nor the link, because its URL answers 404', function (): void {
    [$organization, $event, $admin] = callPanelScenario();
    $call = callInStatus($event, $admin, CallStatus::Draft);
    CurrentOrganization::set($organization);

    callManager($event)
        ->assertActionHidden(TestAction::make('viewPublicPage')->table($call))
        ->assertActionHidden(TestAction::make('copyPublicLink')->table($call));
});

it('the title of a published call links to its public page in a new tab, and a draft title is plain text', function (): void {
    [$organization, $event, $admin] = callPanelScenario();
    $published = callInStatus($event, $admin, CallStatus::Published);
    $draft = callInStatus($event, $admin, CallStatus::Draft);
    CurrentOrganization::set($organization);

    $component = callManager($event);
    $column = $component->instance()->getTable()->getColumn('title');

    expect($column->record($published)->getUrl())->toBe(route('calls.show', ['organization' => $organization->slug, 'event' => $event->slug, 'call' => $published->slug]))
        ->and($column->shouldOpenUrlInNewTab())->toBeTrue()
        ->and($column->record($draft)->getUrl())->toBeNull();
});

it('CA-004-39: a reviewer does not reach the calls manager, so the public page actions are not offered either', function (): void {
    [, $event] = callPanelScenario(OrganizationRole::Reviewer);

    expect(CallsRelationManager::canViewForRecord($event, ViewEvent::class))->toBeFalse();
});

it('CA-004-40: the public URL never carries the slug of another organization', function (): void {
    [$organization, $event, $admin] = callPanelScenario();
    $call = callInStatus($event, $admin, CallStatus::Published);

    $otherOrganization = Organization::factory()->create();
    $otherEvent = CurrentOrganization::run($otherOrganization, fn (): Event => Event::factory()->create(['organization_id' => $otherOrganization->id]));
    CurrentOrganization::set($organization);

    $expected = route('calls.show', ['organization' => $organization->slug, 'event' => $event->slug, 'call' => $call->slug]);

    callManager($event)->assertActionHasUrl(TestAction::make('viewPublicPage')->table($call), $expected);

    expect($expected)->not->toContain($otherOrganization->slug)
        ->and($expected)->not->toContain($otherEvent->slug);
});

it('the calls list stacks the period in two short lines and shows a single status badge', function (): void {
    [$organization, $event, $admin] = callPanelScenario();
    $draft = app(CreateCallForSubmissions::class)->handle($admin, $event, panelCallData($event));
    CurrentOrganization::set($organization);

    callManager($event)
        ->assertTableColumnStateSet('status', CallStatus::Draft->getLabel(), $draft)
        ->assertTableColumnDoesNotExist('public_state')
        ->assertSeeText('Abre 10 ene. 2027 · 9:00 a. m.')
        ->assertSeeText('Cierra 15 feb. 2027 · 11:00 p. m.');
});

it('an admin turns off the back link and adds a custom link from the edit form', function (): void {
    [$organization, $event, $admin] = callPanelScenario();
    $call = app(CreateCallForSubmissions::class)->handle($admin, $event, panelCallData($event));
    CurrentOrganization::set($organization);

    expect($call->fresh()->show_back_link)->toBeTrue();

    callManager($event)->callAction(TestAction::make('editCall')->table($call), [
        ...panelCallData($event),
        'show_back_link' => false,
        'extra_link_label' => 'Sitio del congreso',
        'extra_link_url' => 'https://example.test/congreso',
    ])->assertHasNoActionErrors();

    $call = $call->fresh();

    expect($call->show_back_link)->toBeFalse()
        ->and($call->extra_link_label)->toBe('Sitio del congreso')
        ->and($call->extra_link_url)->toBe('https://example.test/congreso');
});

it('the custom link needs both text and an https address', function (array $link): void {
    [$organization, $event, $admin] = callPanelScenario();
    $call = app(CreateCallForSubmissions::class)->handle($admin, $event, panelCallData($event));
    CurrentOrganization::set($organization);

    callManager($event)->callAction(TestAction::make('editCall')->table($call), [...panelCallData($event), ...$link]);

    expect($call->fresh()->extra_link_url)->toBeNull();
})->with([
    'text without address' => [['extra_link_label' => 'Sitio']],
    'address without text' => [['extra_link_url' => 'https://example.test']],
    'insecure address' => [['extra_link_label' => 'Sitio', 'extra_link_url' => 'http://example.test']],
    'script address' => [['extra_link_label' => 'Sitio', 'extra_link_url' => 'javascript:alert(1)']],
]);
