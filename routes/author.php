<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetOrganizationFromRoute;
use Illuminate\Support\Facades\Route;

// Acceso del autor de una propuesta, sin cuenta (plan 006, F5 y F6). Bajo `/{organization:slug}/events/{event:slug}`
// igual que el sitio público: `SetOrganizationFromRoute` fija la organización antes del binding y ninguna ruta
// usa `withoutOrganizationScope()`.
Route::middleware([SetOrganizationFromRoute::class, HandleInertiaRequests::class])
    ->prefix('{organization:slug}/events/{event:slug}')
    ->scopeBindings()
    ->group(function (): void {
        // Las rutas `/my-submission/...` se añaden aquí (H-006-3, H-006-4 y H-006-5).
    });
