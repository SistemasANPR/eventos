<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/form-helpers.php';

/*
 * Plan 020, F0: el menú del panel de la organización tiene orden y grupos explícitos.
 * Cada prueba usa un solo rol: la caché de permisos de Spatie se comparte dentro del proceso.
 */

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/**
 * Menú lateral tal como lo ve el usuario: una lista de [etiqueta del grupo, etiquetas de sus entradas].
 *
 * @return list<array{0: ?string, 1: list<string>}>
 */
function sidebarFor(User $user, Organization $organization): array
{
    app()->setLocale('es');
    Auth::login($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    $sidebar = [];

    foreach (Filament::getNavigation() as $group) {
        $labels = [];

        /** @var NavigationItem $item */
        foreach (collect($group->getItems())->all() as $item) {
            $labels[] = $item->getLabel();
        }

        $groupLabel = $group->getLabel();
        $sidebar[] = [$groupLabel === null ? null : (string) $groupLabel, $labels];
    }

    return $sidebar;
}

it('CA-020-1: the organization admin sees the panel home, Events and the Organization group with Team and Emails in that order', function (): void {
    [$organization, , $admin] = formScenario(OrganizationRole::OrgAdmin);

    $sidebar = sidebarFor($admin, $organization);

    expect($sidebar)->toHaveCount(2)
        ->and($sidebar[0][0])->toBeNull()
        ->and(array_slice($sidebar[0][1], 1))->toBe([__('events.navigation')])
        ->and($sidebar[1])->toBe([__('navigation.groups.organization'), [__('team.resource.plural'), __('mail_admin.navigation')]]);
});

it('CA-020-2: the organizer sees the Organization group with Emails only, without Team', function (): void {
    [$organization, , $organizer] = formScenario(OrganizationRole::Organizer);

    $sidebar = sidebarFor($organizer, $organization);

    expect($sidebar)->toHaveCount(2)
        ->and(array_slice($sidebar[0][1], 1))->toBe([__('events.navigation')])
        ->and($sidebar[1])->toBe([__('navigation.groups.organization'), [__('mail_admin.navigation')]]);
});

it('CA-020-2: a reviewer without organizer permissions sees only the panel home', function (): void {
    [$organization, , $reviewer] = formScenario(OrganizationRole::Reviewer);

    $sidebar = sidebarFor($reviewer, $organization);

    expect($sidebar)->toHaveCount(1)
        ->and($sidebar[0][0])->toBeNull()
        ->and($sidebar[0][1])->toHaveCount(1);
});

it('CA-020-3: every top-level resource and page of the organization panel declares its navigation sort', function (): void {
    $panel = Filament::getPanel('admin');

    $withMenuEntry = collect($panel->getResources())
        ->filter(fn (string $resource): bool => is_subclass_of($resource, Resource::class)
            && $resource::getParentResource() === null
            && $resource::shouldRegisterNavigation())
        ->merge(collect($panel->getPages())->filter(fn (string $page): bool => $page::shouldRegisterNavigation()))
        ->filter(fn (string $class): bool => str_starts_with($class, 'App\\'));

    expect($withMenuEntry)->not->toBeEmpty();

    $withoutSort = $withMenuEntry->reject(fn (string $class): bool => is_int($class::getNavigationSort()));

    expect($withoutSort->values()->all())->toBe([]);
});

it('CA-020-4: the Organization group label exists in Spanish and English', function (): void {
    foreach (['es', 'en'] as $locale) {
        expect(__('navigation.groups.organization', [], $locale))->not->toBe('navigation.groups.organization');
    }
});

/**
 * Secciones del evento como ítems anidados bajo «Eventos» en el menú lateral (plan 020, CA-020-17).
 * Con evento, simula su ruta: el menú lee el evento de la petición original, como en el panel real.
 *
 * @return list<string>
 */
function eventSections(User $user, Organization $organization, ?Event $event): array
{
    if ($event !== null) {
        withEventRoute($event);
    }

    sidebarFor($user, $organization);

    $labels = [];

    foreach (Filament::getNavigation() as $group) {
        /** @var NavigationItem $item */
        foreach (collect($group->getItems())->all() as $item) {
            if ($item->getLabel() !== __('events.navigation')) {
                continue;
            }

            /** @var NavigationItem $child */
            foreach (collect($item->getChildItems())->all() as $child) {
                $labels[] = $child->getLabel();
            }
        }
    }

    return $labels;
}

/**
 * Secciones del evento: las del plan 020; las categorías del programa se abren desde el botón de Ponentes y Actividades.
 *
 * @return list<string>
 */
function eventSectionLabels(): array
{
    return [
        __('events.sub_navigation.summary'),
        __('forms.navigation'),
        __('submission_panel.navigation'),
        __('program.speakers.navigation'),
        __('program.activities.navigation'),
        // Plan 011, F3b: quien asigna ve la sección «Evaluadores» (organizador y administrador).
        __('review_assignments.reviewers.navigation'),
    ];
}

it('CA-020-17: inside an event the sidebar nests its sections under Events for an admin', function (): void {
    [$organization, $event, $admin] = formScenario(OrganizationRole::OrgAdmin);

    expect(eventSections($admin, $organization, $event))->toBe(eventSectionLabels());
});

it('CA-020-17: the organizer sees the same event sections in the sidebar', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);

    expect(eventSections($organizer, $organization, $event))->toBe(eventSectionLabels());
});

it('CA-020-17: outside an event the sidebar has no nested event sections', function (): void {
    [$organization, , $admin] = formScenario(OrganizationRole::OrgAdmin);

    expect(eventSections($admin, $organization, null))->toBe([]);
});

it('CA-020-17: who cannot access events sees no event sections even inside an event route', function (): void {
    [$organization, $event, $reviewer] = formScenario(OrganizationRole::Reviewer);

    expect(eventSections($reviewer, $organization, $event))->toBe([]);
});
