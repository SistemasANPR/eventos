<?php

declare(strict_types=1);

use App\Http\Controllers\Author\ConsumeAccessLinkController;
use App\Http\Controllers\Author\LogoutAuthorController;
use App\Http\Controllers\Author\RequestAccessLinkController;
use App\Http\Controllers\Author\ShowAccessConfirmationController;
use App\Http\Controllers\Author\ShowAccessLinkRequestController;
use App\Http\Controllers\Author\ShowAuthorEditController;
use App\Http\Controllers\Author\ShowAuthorSubmissionController;
use App\Http\Controllers\Author\UpdateAuthorSubmissionController;
use App\Http\Controllers\Author\WithdrawAuthorSubmissionController;
use App\Http\Middleware\AuthenticateAuthor;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ProtectAuthorPages;
use App\Http\Middleware\SetOrganizationFromRoute;
use Illuminate\Support\Facades\Route;

// Acceso del autor de una propuesta, sin cuenta (plan 006, F5 y F6). Bajo `/{organization:slug}/events/{event:slug}`
// igual que el sitio público: `SetOrganizationFromRoute` fija la organización antes del binding y ninguna ruta
// usa `withoutOrganizationScope()`. Todas llevan `Referrer-Policy: no-referrer` y `Cache-Control: no-store`
// (ProtectAuthorPages): las del token no deben filtrarlo y las de la propuesta no se guardan en cachés.
Route::middleware([SetOrganizationFromRoute::class, HandleInertiaRequests::class, ProtectAuthorPages::class])
    ->prefix('{organization:slug}/events/{event:slug}/my-submission')
    ->scopeBindings()
    ->group(function (): void {
        // Públicas: pedir un enlace (límite de peticiones + Turnstile) y abrirlo (confirmación sin efectos, POST consume).
        Route::get('request-link', ShowAccessLinkRequestController::class)->name('author.request-link.show');
        Route::post('request-link', RequestAccessLinkController::class)->name('author.request-link.store');
        Route::get('access/{token}', ShowAccessConfirmationController::class)->name('author.access.show');
        Route::post('access/{token}', ConsumeAccessLinkController::class)
            ->middleware('throttle:20,1')
            ->name('author.access.consume');

        // Con sesión de autor (guard `author`): la propuesta sale de la sesión, no de la URL.
        Route::middleware(AuthenticateAuthor::class)->group(function (): void {
            Route::get('/', ShowAuthorSubmissionController::class)->name('author.submission.show');
            Route::get('edit', ShowAuthorEditController::class)->name('author.edit');
            Route::patch('edit', UpdateAuthorSubmissionController::class)->name('author.update');
            // Cambio de estado: solo POST con CSRF (un GET responde 405).
            Route::post('withdraw', WithdrawAuthorSubmissionController::class)->name('author.withdraw');
            // Cerrar la sesión a mano (plan 034, DA-5): solo POST con CSRF.
            Route::post('logout', LogoutAuthorController::class)->name('author.logout');
        });
    });
