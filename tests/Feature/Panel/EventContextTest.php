<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/form-helpers.php';

/*
 * Plan 020 (ajuste del 2026-11-25): dentro de un evento el encabezado dice en qué evento se está y el
 * menú lateral marca una sola entrada como activa.
 */

/** Texto de la marca de la barra superior (`.fi-logo`), sin etiquetas. */
function topbarBrand(string $html): string
{
    preg_match('/<div[^>]*class="[^"]*fi-logo[^"]*"[^>]*>(.*?)<\/div>/s', $html, $match);

    return trim(strip_tags($match[1] ?? ''));
}

/**
 * Etiquetas de las entradas del menú lateral marcadas como activas.
 *
 * @return list<string>
 */
function activeSidebarItems(string $html): array
{
    preg_match_all('/<li[^>]*class="[^"]*fi-sidebar-item[^"]*fi-active[^"]*"[^>]*>(.*?)<\/li>/s', $html, $matches);

    return array_map(static fn (string $item): string => trim((string) preg_replace('/\s+/', ' ', strip_tags($item))), $matches[1]);
}

it('CA-020-20: inside an event the header shows the name of the event instead of the platform name', function (): void {
    [$organization, $event, , $admin] = formScenario(OrganizationRole::OrgAdmin);

    foreach (['', '/edit', '/submissions', '/form-definitions'] as $suffix) {
        $html = $this->actingAs($admin)->get("/admin/{$organization->slug}/events/{$event->id}{$suffix}")->assertOk()->getContent();

        expect(topbarBrand($html))->toBe('Congreso');
    }
});

it('CA-020-20: outside an event the header keeps the platform name', function (): void {
    [$organization, , , $admin] = formScenario(OrganizationRole::OrgAdmin);

    $html = $this->actingAs($admin)->get("/admin/{$organization->slug}/events")->assertOk()->getContent();

    expect(topbarBrand($html))->toBe(config('app.name'));
});

it('CA-020-20: the header shows the name in the language of the person and escapes it', function (): void {
    [$organization, $event, , $admin] = formScenario(OrganizationRole::OrgAdmin);
    $event->setTranslation('name', 'es', '<b>Foro</b> & Co')->save();

    $html = $this->actingAs($admin)->get("/admin/{$organization->slug}/events/{$event->id}")->assertOk()->getContent();

    expect(topbarBrand($html))->toBe('&lt;b&gt;Foro&lt;/b&gt; &amp; Co');
});

it('CA-020-20: the header never shows the name of an event of another organization', function (): void {
    [$organizationA, , , $adminA] = formScenario(OrganizationRole::OrgAdmin);
    [, $eventB] = formScenario(OrganizationRole::OrgAdmin);

    $this->actingAs($adminA)->get("/admin/{$organizationA->slug}/events/{$eventB->id}")->assertNotFound();
});

it('CA-020-21: inside an event only the current section is active, not Events', function (): void {
    [$organization, $event, , $admin] = formScenario(OrganizationRole::OrgAdmin);

    $submissions = $this->actingAs($admin)->get("/admin/{$organization->slug}/events/{$event->id}/submissions")->assertOk()->getContent();
    $summary = $this->actingAs($admin)->get("/admin/{$organization->slug}/events/{$event->id}")->assertOk()->getContent();

    expect(activeSidebarItems($submissions))->toBe([__('submission_panel.navigation')])
        ->and(activeSidebarItems($summary))->toBe([__('events.sub_navigation.summary')]);
});

it('CA-020-21: in the list of events and when creating one, Events is the active entry', function (): void {
    [$organization, , , $admin] = formScenario(OrganizationRole::OrgAdmin);

    foreach (['/events', '/events/create'] as $path) {
        $html = $this->actingAs($admin)->get("/admin/{$organization->slug}{$path}")->assertOk()->getContent();

        expect(activeSidebarItems($html))->toBe([__('events.navigation')]);
    }
});
