<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Resources\Events\RelationManagers\CallsRelationManager;
use App\Filament\Resources\Events\RelationManagers\PrivacyNoticesRelationManager;
use App\Filament\Resources\Events\RelationManagers\SubmissionTypesRelationManager;
use App\Filament\Resources\Events\RelationManagers\TracksRelationManager;
use App\Filament\Resources\TeamMembers\Pages\ListTeamMembers;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Support\Enums\Width;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

/*
 * Plan 015, F1 (DA-5): todos los modales de acción del panel miden `md` y los
 * formularios con más de cuatro campos se abren como slide-over.
 */

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/**
 * Entra al panel como administrador de una organización con un evento en los idiomas dados.
 *
 * @param  list<string>  $locales
 * @return array{0: Organization, 1: Event}
 */
function modalScenario(array $locales = ['es', 'en']): array
{
    $organization = Memberships::organization();
    $user = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $event = Event::factory()->create([
        'organization_id' => $organization->id,
        'locales' => $locales,
        'default_locale' => 'es',
    ]);

    Auth::login($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);
    // El ancho por omisión se define al arrancar el panel; una petición real lo arranca con su middleware.
    Filament::bootCurrentPanel();

    return [$organization, $event];
}

/**
 * @param  class-string<Component>  $manager
 * @return Testable<Component>
 */
function eventManager(string $manager, Event $event): Testable
{
    return Livewire::test($manager, ['ownerRecord' => $event, 'pageClass' => ViewEvent::class]);
}

/**
 * La acción cuyo modal quedó montado, para leer su ancho y su modo.
 *
 * @param  Testable<Component>  $component
 */
function mountedModal(Testable $component): Action
{
    // `getMountedAction()` vive en el trait InteractsWithActions, no en la interfaz HasActions.
    $instance = $component->instance();
    $action = method_exists($instance, 'getMountedAction') ? $instance->getMountedAction() : null;

    return $action instanceof Action ? $action : throw new LogicException('No hay una acción montada.');
}

it('CA-015-3: any action of the admin panel, including the built-in ones, defaults to the md width', function (): void {
    modalScenario();

    foreach ([Action::make('plain'), CreateAction::make(), EditAction::make(), ViewAction::make()] as $action) {
        expect($action->getModalWidth())->toBe(Width::Medium);
    }
});

it('CA-015-3: a width set explicitly on one action still wins over the default', function (): void {
    modalScenario();

    expect(Action::make('wide')->modalWidth(Width::FourExtraLarge)->getModalWidth())->toBe(Width::FourExtraLarge);
});

it('CA-015-3: the short forms of the panel open as a md modal', function (): void {
    [, $event] = modalScenario();

    $invite = mountedModal(Livewire::test(ListTeamMembers::class)->mountAction('invite'));
    $type = mountedModal(eventManager(SubmissionTypesRelationManager::class, $event)->mountAction(TestAction::make('createType')->table()));
    $notice = mountedModal(eventManager(PrivacyNoticesRelationManager::class, $event)->mountAction(TestAction::make('createDraft')->table()));
    $track = mountedModal(eventManager(TracksRelationManager::class, $event)->mountAction(TestAction::make('createTrack')->table()));

    foreach ([$invite, $type, $notice, $track] as $action) {
        expect($action->getModalWidth())->toBe(Width::Medium)
            ->and($action->isModalSlideOver())->toBeFalse();
    }
});

it('CA-015-3: the call form (more than four fields) opens as a slide-over', function (): void {
    [, $event] = modalScenario();

    $create = mountedModal(eventManager(CallsRelationManager::class, $event)->mountAction(TestAction::make('createCall')->table()));

    expect($create->isModalSlideOver())->toBeTrue()
        ->and($create->getModalWidth())->toBe(Width::Medium);
});

it('CA-015-3: the track form becomes a slide-over once the event offers three languages (six fields)', function (): void {
    [, $event] = modalScenario(['es', 'en', 'pt']);

    $track = mountedModal(eventManager(TracksRelationManager::class, $event)->mountAction(TestAction::make('createTrack')->table()));

    expect($track->isModalSlideOver())->toBeTrue();
});
