<?php

declare(strict_types=1);

use App\Actions\Calls\ChangeCallStatus;
use App\Actions\Calls\CreateCallForSubmissions;
use App\Actions\Calls\PublishCall;
use App\Actions\Privacy\CreatePrivacyNoticeDraft;
use App\Actions\Privacy\PublishPrivacyNotice;
use App\Enums\CallStatus;
use App\Enums\EventStatus;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Resources\Events\RelationManagers\CallsRelationManager;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Actions\Action;
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

/** El evento pasa al estado dado (plan 030): la página pública de una convocatoria depende también de él. */
function setPanelEventStatus(Event $event, EventStatus $status): void
{
    $event->forceFill(['status' => $status])->save();
}

it('CA-004-37: a published, paused or closed call offers its public page, opened in a new tab, with the URL of its own organization, event and call', function (CallStatus $status): void {
    [$organization, $event, $admin] = callPanelScenario();
    setPanelEventStatus($event, EventStatus::Published);
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
    setPanelEventStatus($event, EventStatus::Published);
    $call = callInStatus($event, $admin, CallStatus::Draft);
    CurrentOrganization::set($organization);

    callManager($event)
        ->assertActionHidden(TestAction::make('viewPublicPage')->table($call))
        ->assertActionHidden(TestAction::make('copyPublicLink')->table($call));
});

it('the title of a published call links to its public page in a new tab, and a draft title is plain text', function (): void {
    [$organization, $event, $admin] = callPanelScenario();
    setPanelEventStatus($event, EventStatus::Published);
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
    setPanelEventStatus($event, EventStatus::Published);
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
        'extra_links' => [
            ['label' => 'Sitio del congreso', 'url' => 'https://example.test/congreso'],
            ['label' => 'Programa', 'url' => 'https://example.test/programa'],
            ['label' => '', 'url' => ''],
        ],
    ])->assertHasNoActionErrors();

    $call = $call->fresh();

    // Varios enlaces, en su orden; la fila vacía se descarta.
    expect($call->show_back_link)->toBeFalse()
        ->and($call->extra_links)->toBe([
            ['label' => 'Sitio del congreso', 'url' => 'https://example.test/congreso'],
            ['label' => 'Programa', 'url' => 'https://example.test/programa'],
        ]);
});

it('a call accepts at most 10 custom links', function (int $count, bool $accepted): void {
    [$organization, $event, $admin] = callPanelScenario();
    $call = app(CreateCallForSubmissions::class)->handle($admin, $event, panelCallData($event));
    CurrentOrganization::set($organization);
    $links = array_map(fn (int $n): array => ['label' => "Enlace {$n}", 'url' => "https://example.test/{$n}"], range(1, $count));

    callManager($event)->callAction(TestAction::make('editCall')->table($call), [...panelCallData($event), 'extra_links' => $links]);

    expect(count($call->fresh()->extra_links ?? []))->toBe($accepted ? $count : 0);
})->with([[10, true], [11, false]]);

it('every custom link needs both text and an https address', function (array $link): void {
    [$organization, $event, $admin] = callPanelScenario();
    $call = app(CreateCallForSubmissions::class)->handle($admin, $event, panelCallData($event));
    CurrentOrganization::set($organization);

    callManager($event)->callAction(TestAction::make('editCall')->table($call), [...panelCallData($event), 'extra_links' => [$link]]);

    expect($call->fresh()->extra_links)->toBeNull();
})->with([
    'text without address' => [['label' => 'Sitio', 'url' => '']],
    'address without text' => [['label' => '', 'url' => 'https://example.test']],
    'insecure address' => [['label' => 'Sitio', 'url' => 'http://example.test']],
    'script address' => [['label' => 'Sitio', 'url' => 'javascript:alert(1)']],
]);

/**
 * La acción de la fila, tal como la arma la tabla (con su tooltip y su estado deshabilitado).
 *
 * @param  Testable<CallsRelationManager>  $component
 */
function panelRowAction(Testable $component, string $name, CallForSubmissions $call): Action
{
    $action = $component->instance()->getTable()->getFlatRecordActions()[$name] ?? null;

    expect($action)->toBeInstanceOf(Action::class);

    return $action->record($call);
}

it('CA-030-1: with a published event and a published call, both links are enabled and point to the usual URL', function (): void {
    [$organization, $event, $admin] = callPanelScenario();
    setPanelEventStatus($event, EventStatus::Published);
    $call = callInStatus($event, $admin, CallStatus::Published);
    CurrentOrganization::set($organization);

    $publicUrl = route('calls.show', ['organization' => $organization->slug, 'event' => $event->slug, 'call' => $call->slug]);
    $component = callManager($event);

    $component
        ->assertActionEnabled(TestAction::make('viewPublicPage')->table($call))
        ->assertActionEnabled(TestAction::make('copyPublicLink')->table($call))
        ->assertActionHasUrl(TestAction::make('viewPublicPage')->table($call), $publicUrl);

    expect(panelRowAction($component, 'viewPublicPage', $call)->getTooltip())->toBeNull()
        ->and(CallsRelationManager::getBadge($event, ViewEvent::class))->toBeNull();
});

it('CA-030-2: with an archived event both links are disabled, have no URL and explain it', function (CallStatus $status): void {
    [$organization, $event, $admin] = callPanelScenario();
    setPanelEventStatus($event, EventStatus::Published);
    $call = callInStatus($event, $admin, $status);
    setPanelEventStatus($event, EventStatus::Archived);
    CurrentOrganization::set($organization);

    $component = callManager($event->fresh());

    $component
        ->assertActionVisible(TestAction::make('viewPublicPage')->table($call))
        ->assertActionDisabled(TestAction::make('viewPublicPage')->table($call))
        ->assertActionVisible(TestAction::make('copyPublicLink')->table($call))
        ->assertActionDisabled(TestAction::make('copyPublicLink')->table($call))
        ->assertActionDoesNotHaveUrl(TestAction::make('viewPublicPage')->table($call), route('calls.show', ['organization' => $organization->slug, 'event' => $event->slug, 'call' => $call->slug]));

    expect(panelRowAction($component, 'viewPublicPage', $call)->getUrl())->toBeNull()
        ->and(panelRowAction($component, 'viewPublicPage', $call)->getTooltip())->toBe(__('calls.actions.public_unavailable.archived'))
        ->and(panelRowAction($component, 'copyPublicLink', $call)->getTooltip())->toBe(__('calls.actions.public_unavailable.archived'))
        ->and($component->instance()->getTable()->getColumn('title')->record($call)->getUrl())->toBeNull();
})->with([
    'published' => [CallStatus::Published],
    'paused' => [CallStatus::Paused],
    'closed' => [CallStatus::Closed],
]);

it('CA-030-3: with a draft event both links are disabled and say the event is not published yet', function (): void {
    [$organization, $event, $admin] = callPanelScenario();
    setPanelEventStatus($event, EventStatus::Published);
    $call = callInStatus($event, $admin, CallStatus::Published);
    setPanelEventStatus($event, EventStatus::Draft);
    CurrentOrganization::set($organization);

    $component = callManager($event->fresh());

    $component
        ->assertActionDisabled(TestAction::make('viewPublicPage')->table($call))
        ->assertActionDisabled(TestAction::make('copyPublicLink')->table($call));

    expect(panelRowAction($component, 'viewPublicPage', $call)->getUrl())->toBeNull()
        ->and(panelRowAction($component, 'viewPublicPage', $call)->getTooltip())->toBe(__('calls.actions.public_unavailable.draft_event'))
        ->and(panelRowAction($component, 'copyPublicLink', $call)->getTooltip())->toBe(__('calls.actions.public_unavailable.draft_event'));
});

it('CA-030-4: a draft call of a published event offers no public link at all', function (): void {
    [$organization, $event, $admin] = callPanelScenario();
    setPanelEventStatus($event, EventStatus::Published);
    $call = callInStatus($event, $admin, CallStatus::Draft);
    CurrentOrganization::set($organization);

    callManager($event)
        ->assertActionHidden(TestAction::make('viewPublicPage')->table($call))
        ->assertActionHidden(TestAction::make('copyPublicLink')->table($call));
});

it('CA-030-5: the calls section shows the archived-event badge only while the event is archived', function (): void {
    [, $event] = callPanelScenario();

    setPanelEventStatus($event, EventStatus::Published);
    expect(CallsRelationManager::getBadge($event, ViewEvent::class))->toBeNull();

    setPanelEventStatus($event, EventStatus::Archived);
    expect(CallsRelationManager::getBadge($event, ViewEvent::class))->toBe(__('calls.actions.event_archived_badge'));

    setPanelEventStatus($event, EventStatus::Draft);
    expect(CallsRelationManager::getBadge($event, ViewEvent::class))->toBeNull();
});
