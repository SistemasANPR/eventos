<?php

declare(strict_types=1);

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
function panelCallData(): array
{
    return [
        'submission_type' => 'session',
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
    $call = app(CreateCallForSubmissions::class)->handle($admin, $event, panelCallData());
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
        ->callAction(TestAction::make('createCall')->table(), panelCallData())
        ->assertHasNoFormErrors();

    CurrentOrganization::set($organization);
    $call = CallForSubmissions::query()->sole();

    expect($call->status)->toBe(CallStatus::Draft)
        ->and($call->opens_at->utc()->format('Y-m-d H:i'))->toBe('2027-01-10 15:00')
        ->and($call->getTranslation('title', 'en'))->toBe('Call for sessions');
});

it('CA-004-2: the panel saves nothing when the dates are invalid', function (): void {
    [$organization, $event] = callPanelScenario();

    callManager($event)->callAction(TestAction::make('createCall')->table(), [...panelCallData(), 'closes_at' => '2027-01-09 09:00']);

    CurrentOrganization::set($organization);

    expect(CallForSubmissions::query()->count())->toBe(0);
});

it('CA-004-9: an admin publishes a call from the panel when the notice is published', function (): void {
    [$organization, $event, $admin] = callPanelScenario();
    publishNoticeInBothLanguages($event, $admin);
    $call = app(CreateCallForSubmissions::class)->handle($admin, $event, panelCallData());
    CurrentOrganization::set($organization);

    callManager($event)->callAction(TestAction::make('publish')->table($call));

    expect($call->fresh()->status)->toBe(CallStatus::Published);
});

it('CA-004-10: publishing without the privacy notice leaves the call in draft', function (): void {
    [$organization, $event, $admin] = callPanelScenario();
    $call = app(CreateCallForSubmissions::class)->handle($admin, $event, panelCallData());
    CurrentOrganization::set($organization);

    callManager($event)->callAction(TestAction::make('publish')->table($call));

    expect($call->fresh()->status)->toBe(CallStatus::Draft);
});

it('CA-004-9: a published call offers no publish action again', function (): void {
    [$organization, $event, $admin] = callPanelScenario();
    publishNoticeInBothLanguages($event, $admin);
    $call = app(CreateCallForSubmissions::class)->handle($admin, $event, panelCallData());
    $published = app(PublishCall::class)->handle($admin, $call);
    CurrentOrganization::set($organization);

    callManager($event)->assertActionHidden(TestAction::make('publish')->table($published));
});

it('CA-004-1: the edit form shows the dates in the local time of the event, not in UTC', function (): void {
    [$organization, $event, $admin] = callPanelScenario();
    $call = app(CreateCallForSubmissions::class)->handle($admin, $event, panelCallData());
    CurrentOrganization::set($organization);

    $manager = callManager($event)->mountAction(TestAction::make('editCall')->table($call));

    expect($manager->get('mountedActions.0.data.opens_at'))->toBe('2027-01-10 09:00')
        ->and($manager->get('mountedActions.0.data.closes_at'))->toBe('2027-02-15 23:00');
});

it('CA-004-11: an admin edits a call from the panel and the new close is stored in UTC', function (): void {
    [$organization, $event, $admin] = callPanelScenario();
    $call = app(CreateCallForSubmissions::class)->handle($admin, $event, panelCallData());
    CurrentOrganization::set($organization);

    callManager($event)->callAction(TestAction::make('editCall')->table($call), [...panelCallData(), 'closes_at' => '2027-02-20 23:00']);

    expect($call->fresh()->closes_at->utc()->format('Y-m-d H:i'))->toBe('2027-02-21 05:00');
});

it('CA-004-6: the panel offers no way to delete a call', function (): void {
    [$organization, $event, $admin] = callPanelScenario();
    $call = app(CreateCallForSubmissions::class)->handle($admin, $event, panelCallData());
    CurrentOrganization::set($organization);

    callManager($event)
        ->assertActionDoesNotExist(TestAction::make('delete')->table($call))
        ->assertActionDoesNotExist(TestAction::make('forceDelete')->table($call));
});
