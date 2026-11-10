<?php

declare(strict_types=1);

/*
 * Plan 015, F1: las pantallas de acceso del panel llevan la identidad de marca
 * del tema, sin recursos de terceros.
 */

dataset('access screens', [
    'login' => '/admin/login',
    'request password reset' => '/admin/password-reset/request',
]);

it('CA-015-8: the access screens render the themed simple layout without external hosts', function (string $url): void {
    $this->withoutVite();

    $html = $this->get($url)->assertOk()->getContent();

    expect($html)->toContain('fi-simple-layout')
        ->toContain('fi-simple-header-heading')
        ->toContain('fi-logo')
        ->and($html)->not->toMatch('/(src|href)="https?:\/\/(?!localhost)/');
})->with('access screens');

it('CA-015-8: the theme styles the access layout with brand tokens, no third parties and a visible focus', function (): void {
    $css = (string) file_get_contents(resource_path('css/filament/admin/theme.css'));

    expect($css)->toContain('.fi-simple-layout')
        ->toContain('.fi-simple-main')
        ->toContain('.fi-logo')
        ->toContain('focus-visible')
        ->and($css)->not->toMatch('/https?:\/\//');
});
