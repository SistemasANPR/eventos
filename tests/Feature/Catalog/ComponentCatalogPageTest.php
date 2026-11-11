<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Finder\Finder;

uses(RefreshDatabase::class);

/*
 * Catálogo interno de componentes (design.md §1.4, plan 002, DT-06): herramienta
 * del equipo, sin datos de organizaciones. Visible en local y pruebas; fuera de
 * ahí solo para usuarios de plataforma, y a los demás les responde 404 (no 403)
 * para no revelar que la ruta existe.
 */
beforeEach(function (): void {
    $this->withoutVite();
});

/** Simula el entorno de la aplicación para la petición de la prueba. */
function inEnvironment(string $environment): void
{
    app()->detectEnvironment(static fn (): string => $environment);
}

it('CA-002-27: a visitor without session sees the catalog in local and testing environments', function (string $environment): void {
    inEnvironment($environment);

    $this->get('/components')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Components/Index'));

    expect(route('components.index', absolute: false))->toBe('/components');
})->with(['local', 'testing']);

it('CA-002-28: outside local, a visitor without session gets 404, not 403', function (string $environment): void {
    inEnvironment($environment);

    $this->get('/components')->assertNotFound();
})->with(['production', 'staging']);

it('CA-002-28: outside local, a signed-in user who is not a platform user gets 404', function (): void {
    inEnvironment('production');

    $this->actingAs(User::factory()->create())->get('/components')->assertNotFound();
});

it('CA-002-28: outside local, a platform user sees the catalog', function (string $environment): void {
    inEnvironment($environment);

    $this->actingAs(User::factory()->platformAdmin()->create())
        ->get('/components')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Components/Index'));
})->with(['production', 'staging']);

it('CA-002-29: the response asks search engines not to index it', function (): void {
    $this->get('/components')
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('CA-002-29: no menu or page links to the internal catalog', function (): void {
    $root = dirname(__DIR__, 3);
    $finder = (new Finder)->files()
        ->in([$root.'/app/Filament', $root.'/resources/js', $root.'/resources/views'])
        ->notPath('catalog')
        ->notPath('pages/Components')
        ->notName('*.test.*');

    $offenders = [];

    foreach ($finder as $file) {
        if (preg_match('#components\.index|[\'"]/components[\'"/]#', $file->getContents()) === 1) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([], 'Enlaces al catálogo interno: '.implode(', ', $offenders));
});
