<?php

declare(strict_types=1);

use App\Actions\Events\CreateEvent as CreateEventAction;
use App\Enums\OrganizationRole;
use App\Models\User;
use App\Support\Panel\BrandPalette;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Finder\Finder;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

/*
 * Plan 015, F3: el acabado visual del panel sale solo de los tokens de design.md,
 * cumple contraste AA, anima poco y apunta a clases de Filament que existen.
 */

function styleCss(): string
{
    return strtolower((string) file_get_contents(resource_path('css/filament/admin/theme.css')));
}

/** `rgb(r, g, b)` de BrandPalette a `#rrggbb`. */
function styleHexFromRgb(string $rgb): string
{
    preg_match('/(\d+),\s*(\d+),\s*(\d+)/', $rgb, $m);

    return sprintf('#%02x%02x%02x', $m[1], $m[2], $m[3]);
}

/** @return list<string> Hexadecimales (6 dígitos) que la guía permite usar. */
function styleAllowedHex(): array
{
    preg_match_all('/#[0-9a-f]{6}\b/', strtolower((string) file_get_contents(resource_path('css/app.css'))), $fromTokens);

    $fromScales = array_map(styleHexFromRgb(...), [...BrandPalette::primary(), ...BrandPalette::gray()]);

    return array_values(array_unique([...$fromTokens[0], ...$fromScales, '#ffffff']));
}

/** Luminancia relativa WCAG de `#rrggbb`. */
function styleLuminance(string $hex): float
{
    [$r, $g, $b] = array_map(
        static fn (int $channel): float => ($c = $channel / 255) <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4,
        sscanf($hex, '#%02x%02x%02x'),
    );

    return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
}

function styleContrast(string $foreground, string $background): float
{
    [$light, $dark] = [max(styleLuminance($foreground), styleLuminance($background)), min(styleLuminance($foreground), styleLuminance($background))];

    return ($light + 0.05) / ($dark + 0.05);
}

it('CA-015-9: every hex color of the theme belongs to the design.md tokens', function (): void {
    preg_match_all('/#[0-9a-f]{3,8}\b/', styleCss(), $found);
    $loose = array_values(array_diff(array_unique($found[0]), styleAllowedHex()));

    expect($loose)->toBe([]);
});

it('CA-015-10: the new text and background pairs reach AA and are the ones the theme uses', function (): void {
    // [texto, fondo, mínimo]: 4,5 para texto; 3 para bordes de campo y glifos.
    $pairs = [
        'sidebar item' => ['#cfdbf6', '#152b83', 4.5],
        'sidebar active item' => ['#ffffff', '#0d1e70', 4.5],
        'page heading' => ['#152b83', '#f8fafe', 4.5],
        'page subheading' => ['#585b62', '#f8fafe', 4.5],
        'table header' => ['#585b62', '#f1f3f8', 4.5],
        'table row hover' => ['#243342', '#f4f7fe', 4.5],
        'lime action' => ['#152b83', '#bfcc36', 4.5],
        'field border' => ['#777a82', '#ffffff', 3.0],
    ];
    $css = styleCss();

    foreach ($pairs as $name => [$foreground, $background, $minimum]) {
        // `$name` identifica el par en la salida si alguna aserción falla.
        expect([$name => styleContrast($foreground, $background) >= $minimum])->toBe([$name => true])
            ->and($css)->toContain($foreground)
            ->and($css)->toContain($background);
    }
});

it('CA-015-11: the theme only animates transform, opacity and shadow, briefly and respecting reduced motion', function (): void {
    $css = styleCss();
    preg_match_all('/transition(?:-property)?:\s*([^;]+);/', $css, $declarations);

    expect($css)->toContain('prefers-reduced-motion: reduce')
        ->and($declarations[1])->not->toBe([]);

    foreach ($declarations[1] as $value) {
        expect($value)->not->toContain('all')
            ->and(preg_replace('/(transform|opacity|box-shadow|none|\d+ms|ease-out|ease|,|\s)+/', '', $value))->toBe('');
    }
});

it('CA-015-12: every Filament class the theme styles exists in Filament', function (): void {
    preg_match_all('/\.(fi-[a-z0-9-]+)/', styleCss(), $classes);
    $vendor = '';

    foreach ((new Finder)->files()->in(base_path('vendor/filament'))->path('resources')->name(['*.blade.php', '*.css']) as $file) {
        $vendor .= $file->getContents();
    }

    $missing = array_values(array_filter(
        array_unique($classes[1]),
        static fn (string $class): bool => ! str_contains($vendor, $class),
    ));

    expect($missing)->toBe([]);
});

it('CA-015-12: the pages of the panel render the structure the theme styles', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    app(CreateEventAction::class)->handle($admin, $organization, [
        'name' => ['es' => 'Congreso'],
        'description' => ['es' => 'Descripción'],
        'slug' => 'congreso',
        'type' => 'congress',
        'starts_at' => '2027-03-10 09:00',
        'ends_at' => '2027-03-12 17:00',
        'timezone' => 'America/Mexico_City',
        'venue_name' => 'Sede',
        'venue_address' => 'Calle 1',
        'city' => 'Ciudad de México',
        'locales' => ['es'],
        'default_locale' => 'es',
        'currency' => 'MXN',
    ]);

    $html = $this->actingAs($admin)->get("/admin/{$organization->slug}/events")->assertOk()->getContent();

    foreach (['fi-sidebar', 'fi-topbar', 'fi-header-heading', 'fi-ta-record'] as $class) {
        expect($html)->toContain($class);
    }
});

it('CA-015-14: the sidebar is narrow and collapsible on desktop', function (): void {
    $panel = Filament::getPanel('admin');

    expect((float) $panel->getSidebarWidth())->toBeLessThanOrEqual(16.0)
        ->and($panel->getSidebarWidth())->toEndWith('rem')
        ->and($panel->isSidebarCollapsibleOnDesktop())->toBeTrue();
});

it('CA-015-14: the organization name of the selector is legible on the navy sidebar', function (): void {
    $css = styleCss();

    expect($css)->toContain('.fi-tenant-menu-trigger-tenant-name')
        ->and($css)->toContain('.fi-tenant-menu-trigger-current-tenant-label')
        ->and(styleContrast('#ffffff', '#152b83'))->toBeGreaterThanOrEqual(4.5)
        ->and(styleContrast('#cfdbf6', '#152b83'))->toBeGreaterThanOrEqual(4.5);
});
