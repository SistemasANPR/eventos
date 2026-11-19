<?php

declare(strict_types=1);

use App\Http\Controllers\Internal\ShowComponentCatalogController;
use App\Http\Controllers\Panel\ShowLandingEditorImageController;
use App\Http\Controllers\Panel\SubmissionExportDownloadController;
use App\Http\Controllers\Panel\SubmissionFileDownloadController;
use App\Http\Controllers\Public\ShowActivityMediaController;
use App\Http\Controllers\Public\ShowCallController;
use App\Http\Controllers\Public\ShowEventController;
use App\Http\Controllers\Public\ShowLandingImageController;
use App\Http\Controllers\Public\ShowPrivacyNoticeController;
use App\Http\Controllers\Public\ShowSpeakerPhotoController;
use App\Http\Controllers\Public\ShowSubmissionConfirmationController;
use App\Http\Controllers\Public\ShowSubmissionFormController;
use App\Http\Controllers\Public\StoreSubmissionController;
use App\Http\Controllers\Public\SubmissionFileUploadController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PreventCaching;
use App\Http\Middleware\SetOrganizationFromRoute;
use Illuminate\Support\Facades\Route;

// La raíz no tiene página propia: lleva al inicio de sesión, que es la puerta de entrada de la plataforma.
Route::redirect('/', '/admin/login');

// El inicio de sesión de `/admin/login` es el único acceso, de organizadores y de plataforma (CA-001-34, plan 017).
// La ruta con nombre `login` existe porque el framework redirige a ella a los invitados;
// `/register` no existe y responde 404.
Route::redirect('/login', '/admin/login')->name('login');

// Catálogo interno de componentes (design.md §1.4): sin organización, sin enlace en menús y con
// acceso restringido por entorno o por plataforma (404 si no se cumple). Ver el controlador.
Route::middleware(HandleInertiaRequests::class)
    ->get('components', ShowComponentCatalogController::class)
    ->name('components.index');

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
        // Imágenes del programa (plan 021, CA-021-18): el disco `media` es privado y solo se sirven las
        // de registros publicados y no borrados, de un evento publicado.
        Route::get('events/{event:slug}/speakers/{speaker}/photo', ShowSpeakerPhotoController::class)->name('public.speakers.photo');
        Route::get('events/{event:slug}/activities/{activity}/{type}', ShowActivityMediaController::class)
            ->whereIn('type', ['icon', 'image'])
            ->name('public.activities.media');
        // Imágenes de la landing (plan 022, CA-022-32): solo las de la versión publicada, de un evento publicado.
        Route::get('events/{event:slug}/landing/images/{image}', ShowLandingImageController::class)->name('public.landings.image');
        // Subida pública de archivos de propuesta (plan 007): responde JSON y se acota con token y cuotas.
        Route::post('events/{event:slug}/call/{call}/files', SubmissionFileUploadController::class)
            ->middleware('throttle:60,1')
            ->name('calls.files.store');
        // Formulario público de envío (plan 006, H-006-7). Sin caché: lleva claves emitidas a la sesión.
        Route::middleware(PreventCaching::class)->group(function (): void {
            Route::get('events/{event:slug}/call/{call:slug}/submit', ShowSubmissionFormController::class)->name('calls.submit.show');
            Route::post('events/{event:slug}/call/{call:slug}/submit', StoreSubmissionController::class)
                ->middleware('throttle:60,1')
                ->name('calls.submit.store');
            Route::get('events/{event:slug}/call/{call:slug}/submitted', ShowSubmissionConfirmationController::class)->name('calls.submitted');
        });
    });

// Descarga de archivos de propuesta por organizadores (plan 007, H-007-2). Bajo el prefijo `admin/`
// del panel de Filament, pero como ruta web propia: autenticada con `auth` y con la organización
// fijada por la ruta. Decisión: `tenantRoutes()` de Filament exigiría tocar el proveedor del panel.
Route::middleware(['auth', SetOrganizationFromRoute::class])
    ->get('admin/{organization:slug}/submission-files/{file}', SubmissionFileDownloadController::class)
    ->name('submission-files.download');

// Imágenes de la landing para el editor del panel (plan 022, DA-7): también las del borrador. Misma forma que
// la descarga de archivos de propuesta; `events.manage` lo decide la Policy dentro del controlador.
Route::middleware(['auth', SetOrganizationFromRoute::class])
    ->get('admin/{organization:slug}/events/{event}/landing/images/{image}', ShowLandingEditorImageController::class)
    ->name('landing-editor.images.show');

// Descarga del archivo de una exportación de propuestas (plan 009, H-009-3, CA-009-19). Misma forma que
// la descarga de archivos de propuesta: `auth`, organización fijada por la ruta y, además, URL firmada
// de 5 minutos (`signed`) que lleva el identificador de quien la pidió.
Route::middleware(['auth', 'signed', SetOrganizationFromRoute::class])
    ->get('admin/{organization:slug}/submission-exports/{export}', SubmissionExportDownloadController::class)
    ->name('submission-exports.download');

// Rutas del autor de una propuesta (plan 006).
require __DIR__.'/author.php';
