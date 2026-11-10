<?php

declare(strict_types=1);

use Filament\Facades\Filament;

/*
 * Plan 015, F0: el panel usa los tokens de design.md §3.1 y no pide nada a terceros.
 */

/** Valor hex de un token del tema público (resources/css/app.css), en minúsculas. */
function designToken(string $name): string
{
    preg_match('/--color-'.preg_quote($name, '/').':\s*(#[0-9a-f]{6})/i', (string) file_get_contents(resource_path('css/app.css')), $match);

    return strtolower($match[1] ?? throw new RuntimeException("Token {$name} no encontrado en app.css"));
}

/** Convierte `rgb(r, g, b)` o `#rrggbb` de la paleta del panel a `#rrggbb`. */
function toHex(string $color): string
{
    if (preg_match('/^rgb\((\d+),\s*(\d+),\s*(\d+)\)$/', $color, $m) === 1) {
        return sprintf('#%02x%02x%02x', $m[1], $m[2], $m[3]);
    }

    return strtolower($color);
}

it('CA-015-1: the admin panel loads its own theme from the project', function (): void {
    $panel = Filament::getPanel('admin');

    expect($panel->getViteTheme())->toBe('resources/css/filament/admin/theme.css')
        ->and(resource_path('css/filament/admin/theme.css'))->toBeFile()
        ->and((string) file_get_contents(base_path('vite.config.js')))->toContain('resources/css/filament/admin/theme.css');
});

it('CA-015-1: the login page requests no fonts from third parties', function (): void {
    $this->withoutVite();

    $html = $this->get('/admin/login')->assertOk()->getContent();

    expect($html)->not->toMatch('/fonts\.(bunny\.net|googleapis\.com|gstatic\.com)/');
});

it('CA-015-1: the theme serves its fonts from resources/fonts', function (): void {
    $css = (string) file_get_contents(resource_path('css/filament/admin/theme.css'));

    expect($css)->toContain('Montserrat')->toContain('Roboto')
        ->and($css)->not->toMatch('/https?:\/\//');
});

it('CA-015-2: the panel primary and gray palettes match the design.md tokens', function (): void {
    $colors = Filament::getPanel('admin')->getColors();

    expect(toHex($colors['primary'][600]))->toBe(designToken('primary'))
        ->and(toHex($colors['primary'][700]))->toBe(designToken('primary-hover'))
        ->and(toHex($colors['gray'][500]))->toBe(designToken('border-input'))
        ->and(toHex($colors['gray'][600]))->toBe(designToken('text-muted'))
        ->and(toHex($colors['danger'][600]))->toBe(designToken('error'))
        ->and(toHex($colors['success'][600]))->toBe(designToken('success'))
        ->and(toHex($colors['warning'][600]))->toBe(designToken('warning'))
        ->and(toHex($colors['info'][600]))->toBe(designToken('info'));
});

it('CA-015-2: the panel has a single light mode', function (): void {
    expect(Filament::getPanel('admin')->hasDarkMode())->toBeFalse();
});

it('CA-015-2: every panel color has all the shades 50 to 950 that Filament components read', function (string $name): void {
    /** @var array<int, string> $palette */
    $palette = (array) Filament::getPanel('admin')->getColors()[$name];

    expect(array_keys($palette))->toBe([50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950]);
})->with(['primary', 'gray', 'danger', 'success', 'warning', 'info']);
