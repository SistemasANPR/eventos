<?php

declare(strict_types=1);

use App\Http\Controllers\Public\ShowEventController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetOrganizationFromRoute;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// El inicio de sesión del panel de Filament es el único acceso de organizadores (CA-001-34).
// La ruta con nombre `login` existe porque el framework redirige a ella a los invitados;
// `/register` no existe y responde 404.
Route::redirect('/login', '/admin/login')->name('login');

// Sitio público (ADR-0004, Inertia sin SSR). Un solo esquema de URL: `/{organization:slug}/...`
// (plan 001 D1). `SetOrganizationFromRoute` fija la organización antes del binding y
// `scopeBindings` acota el evento a ella: el de otra organización responde 404.
Route::middleware([SetOrganizationFromRoute::class, HandleInertiaRequests::class])
    ->prefix('{organization:slug}')
    ->scopeBindings()
    ->group(function (): void {
        Route::get('events/{event:slug}', ShowEventController::class)->name('events.show');
    });
