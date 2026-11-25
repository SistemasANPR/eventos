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
        // «Datos de la organización» (CA-020-19), Equipo, Correos y, al final, las dos pantallas de privacidad
        // (plan 014): solo las ve quien tiene sus permisos.
        ->and($sidebar[1])->toBe([__('navigation.groups.organization'), [
            __('organizations.profile.title'),
            __('team.resource.plural'),
            __('mail_admin.navigation'),
            __('privacy_admin.navigation.personal_data'),
            __('privacy_admin.navigation.retention'),
        ]]);
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
 * Grupos de secciones del evento en el menú lateral (plan 020, CA-020-17 y CA-020-18): todos los grupos
 * salvo el de inicio y el de «Organización». Con evento, simula su ruta: el menú lee el evento de la
 * petición original, como en el panel real.
 *
 * @return list<array{0: ?string, 1: list<string>}>
 */
function eventSections(User $user, Organization $organization, ?Event $event): array
{
    if ($event !== null) {
        withEventRoute($event);
    }

    return array_values(array_filter(
        sidebarFor($user, $organization),
        fn (array $group): bool => $group[0] !== null && $group[0] !== __('navigation.groups.organization'),
    ));
}

/**
 * Secciones del evento por fase y en el orden del ciclo de vida: configurar la convocatoria, evaluar y armar el programa.
 * Las categorías del programa se abren desde el botón de Ponentes y Actividades.
 *
 * @return list<array{0: string, 1: list<string>}>
 */
function eventSectionGroups(): array
{
    return [
        [__('navigation.groups.call'), [
            __('events.sub_navigation.summary'),
            __('forms.navigation'),
            __('submission_panel.navigation'),
        ]],
        [__('navigation.groups.evaluation'), [
            // La rúbrica se define antes de asignar evaluadores (plan 011, F4b).
            __('rubrics.navigation'),
            // Plan 011, F3b: quien asigna ve la sección «Evaluadores» (organizador y administrador).
            __('review_assignments.reviewers.navigation'),
            // Plan 011, F6: resultados y avance por evaluador, con sus permisos propios.
            __('review_results.results.navigation'),
            __('review_results.progress.navigation'),
        ]],
        [__('navigation.groups.program'), [
            __('program.speakers.navigation'),
            __('program.activities.navigation'),
        ]],
    ];
}

it('CA-020-17: inside an event the sidebar offers its sections for an admin', function (): void {
    [$organization, $event, $admin] = formScenario(OrganizationRole::OrgAdmin);

    expect(eventSections($admin, $organization, $event))->toBe(eventSectionGroups());
});

it('CA-020-17: the organizer sees the same event sections in the sidebar', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);

    expect(eventSections($organizer, $organization, $event))->toBe(eventSectionGroups());
});

it('CA-020-17: outside an event the sidebar has no event section groups', function (): void {
    [$organization, , $admin] = formScenario(OrganizationRole::OrgAdmin);

    expect(eventSections($admin, $organization, null))->toBe([]);
});

it('CA-020-17: who cannot access events sees no event sections even inside an event route', function (): void {
    [$organization, $event, $reviewer] = formScenario(OrganizationRole::Reviewer);

    expect(eventSections($reviewer, $organization, $event))->toBe([]);
});

it('CA-020-18: the event section groups sit between Events and the Organization group, by phase', function (): void {
    [$organization, $event, $admin] = formScenario(OrganizationRole::OrgAdmin);
    withEventRoute($event);

    $order = array_map(fn (array $group): ?string => $group[0], sidebarFor($admin, $organization));

    expect($order)->toBe([
        null,
        __('navigation.groups.call'),
        __('navigation.groups.evaluation'),
        __('navigation.groups.program'),
        __('navigation.groups.organization'),
    ]);
});

it('CA-020-18: the phase group labels exist in Spanish and English', function (): void {
    foreach (['es', 'en'] as $locale) {
        foreach (['call', 'evaluation', 'program'] as $group) {
            expect(__("navigation.groups.{$group}", [], $locale))->not->toBe("navigation.groups.{$group}");
        }
    }
});

it('CA-020-19: the organization profile entry opens the Organization group for the admin', function (): void {
    [$organization, , $admin] = formScenario(OrganizationRole::OrgAdmin);

    $organizationGroup = sidebarFor($admin, $organization)[1];

    expect($organizationGroup[0])->toBe(__('navigation.groups.organization'))
        ->and($organizationGroup[1][0])->toBe(__('organizations.profile.title'));
});

it('CA-020-19: the organizer, who cannot edit the organization, does not see its profile entry', function (): void {
    [$organization, , $organizer] = formScenario(OrganizationRole::Organizer);

    expect(sidebarFor($organizer, $organization)[1][1])->not->toContain(__('organizations.profile.title'));
});
