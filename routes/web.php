<?php

declare(strict_types=1);

use App\Http\Controllers\Panel\SubmissionFileDownloadController;
use App\Http\Controllers\Public\ShowCallController;
use App\Http\Controllers\Public\ShowEventController;
use App\Http\Controllers\Public\ShowPrivacyNoticeController;
use App\Http\Controllers\Public\SubmissionFileUploadController;
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
        Route::get('events/{event:slug}/privacy', ShowPrivacyNoticeController::class)->name('events.privacy');
        Route::get('events/{event:slug}/call/{call:slug}', ShowCallController::class)->name('calls.show');
        // Subida pública de archivos de propuesta (plan 007): responde JSON y se acota con token y cuotas.
        Route::post('events/{event:slug}/call/{call}/files', SubmissionFileUploadController::class)
            ->middleware('throttle:60,1')
            ->name('calls.files.store');
    });

// Descarga de archivos de propuesta por organizadores (plan 007, H-007-2). Bajo el prefijo `admin/`
// del panel de Filament, pero como ruta web propia: autenticada con `auth` y con la organización
// fijada por la ruta. Decisión: `tenantRoutes()` de Filament exigiría tocar el proveedor del panel.
Route::middleware(['auth', SetOrganizationFromRoute::class])
    ->get('admin/{organization:slug}/submission-files/{file}', SubmissionFileDownloadController::class)
    ->name('submission-files.download');
