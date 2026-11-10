<?php

declare(strict_types=1);

use App\Actions\Privacy\CreatePrivacyNoticeDraft;
use App\Actions\Privacy\PublishPrivacyNotice;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Resources\Events\RelationManagers\PrivacyNoticesRelationManager;
use App\Models\Event;
use App\Models\Organization;
use App\Models\PrivacyNotice;
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
function noticePanelScenario(OrganizationRole $role = OrganizationRole::OrgAdmin): array
{
    $organization = Memberships::organization();
    $user = Memberships::attach(User::factory()->create(), $organization, $role);
    $event = Event::factory()->create(['organization_id' => $organization->id, 'locales' => ['es', 'en'], 'default_locale' => 'es']);

    Auth::login($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    return [$organization, $event, $user];
}

/** @return Testable<PrivacyNoticesRelationManager> */
function noticeManager(Event $event): Testable
{
    return Livewire::test(PrivacyNoticesRelationManager::class, ['ownerRecord' => $event, 'pageClass' => ViewEvent::class]);
}

it('CA-003-11: an admin sees the versions of the notice of the event', function (): void {
    [$organization, $event, $admin] = noticePanelScenario();
    $published = app(PublishPrivacyNotice::class)->handle($admin, app(CreatePrivacyNoticeDraft::class)->handle($admin, $event, ['es' => '<p>Uno</p>']));
    $draft = app(CreatePrivacyNoticeDraft::class)->handle($admin, $event);
    CurrentOrganization::set($organization);

    noticeManager($event)->assertCanSeeTableRecords([$published, $draft]);
});

it('CA-003-18: an organizer does not see the notice manager at all', function (): void {
    [, $event] = noticePanelScenario(OrganizationRole::Organizer);

    expect(PrivacyNoticesRelationManager::canViewForRecord($event, ViewEvent::class))->toBeFalse();
});

it('CA-003-11: an admin creates the first draft from the panel', function (): void {
    [$organization, $event] = noticePanelScenario();

    noticeManager($event)
        ->callAction(TestAction::make('createDraft')->table(), ['body' => ['es' => '<p>Aviso nuevo</p>', 'en' => '<p>New notice</p>']])
        ->assertHasNoFormErrors();

    CurrentOrganization::set($organization);
    $notice = PrivacyNotice::query()->sole();

    expect($notice->version)->toBe(1)
        ->and($notice->published_at)->toBeNull()
        ->and($notice->getTranslation('body', 'en'))->toBe('<p>New notice</p>');
});

it('CA-003-15: the panel sanitizes what is typed before saving', function (): void {
    [$organization, $event] = noticePanelScenario();

    noticeManager($event)->callAction(TestAction::make('createDraft')->table(), ['body' => ['es' => '<p>Hola</p><script>alert(1)</script>']]);

    CurrentOrganization::set($organization);

    expect((string) PrivacyNotice::query()->sole()->getRawOriginal('body'))->not->toContain('script', 'alert');
});

it('CA-003-13: an admin publishes a draft from the panel', function (): void {
    [$organization, $event, $admin] = noticePanelScenario();
    $draft = app(CreatePrivacyNoticeDraft::class)->handle($admin, $event, ['es' => '<p>Aviso</p>']);
    CurrentOrganization::set($organization);

    noticeManager($event)->callAction(TestAction::make('publish')->table($draft));

    expect($draft->fresh()->published_at)->not->toBeNull()
        ->and($draft->fresh()->published_by)->toBe($admin->id);
});

it('CA-003-14: a published version offers neither edit nor publish', function (): void {
    [$organization, $event, $admin] = noticePanelScenario();
    $published = app(PublishPrivacyNotice::class)->handle($admin, app(CreatePrivacyNoticeDraft::class)->handle($admin, $event, ['es' => '<p>Aviso</p>']));
    CurrentOrganization::set($organization);

    noticeManager($event)
        ->assertActionHidden(TestAction::make('publish')->table($published))
        ->assertActionHidden(TestAction::make('edit')->table($published));
});

it('CA-003-14: the new draft starts from the published text', function (): void {
    [$organization, $event, $admin] = noticePanelScenario();
    app(PublishPrivacyNotice::class)->handle($admin, app(CreatePrivacyNoticeDraft::class)->handle($admin, $event, ['es' => '<p>Texto vigente</p>']));
    CurrentOrganization::set($organization);

    $manager = noticeManager($event)->mountAction(TestAction::make('createDraft')->table());

    // El editor guarda su estado como documento (JSON), no como la cadena HTML: se comprueba el contenido.
    expect(json_encode($manager->get('mountedActions.0.data.body.es')))->toContain('Texto vigente');
});

it('CA-003-14: with an open draft the panel offers no second one', function (): void {
    [$organization, $event, $admin] = noticePanelScenario();
    app(CreatePrivacyNoticeDraft::class)->handle($admin, $event, ['es' => '<p>Borrador</p>']);
    CurrentOrganization::set($organization);

    noticeManager($event)->assertActionHidden(TestAction::make('createDraft')->table());
});

it('CA-003-14: the panel offers no way to delete a version', function (): void {
    [$organization, $event, $admin] = noticePanelScenario();
    $draft = app(CreatePrivacyNoticeDraft::class)->handle($admin, $event, ['es' => '<p>Borrador</p>']);
    CurrentOrganization::set($organization);

    noticeManager($event)
        ->assertActionDoesNotExist(TestAction::make('delete')->table($draft))
        ->assertActionDoesNotExist(TestAction::make('forceDelete')->table($draft));
});

it('CA-003-11: an admin edits a draft from the panel and the text is sanitized', function (): void {
    [$organization, $event, $admin] = noticePanelScenario();
    $draft = app(CreatePrivacyNoticeDraft::class)->handle($admin, $event, ['es' => '<p>Original</p>']);
    CurrentOrganization::set($organization);

    noticeManager($event)->callAction(TestAction::make('edit')->table($draft), ['body' => ['es' => '<p>Corregido</p><script>alert(1)</script>']]);

    $stored = (string) $draft->fresh()->getRawOriginal('body');

    expect($stored)->toContain('Corregido');
    expect($stored)->not->toContain('script', 'alert', 'Original');
});
