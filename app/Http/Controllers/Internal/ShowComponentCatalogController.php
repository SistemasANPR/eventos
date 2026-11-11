<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Catálogo interno de componentes: `/components` (design.md §1.4). Herramienta del
 * equipo, sin organización ni datos de negocio: el inventario y las demos viven en
 * el frontend (`catalog/registry.ts`).
 *
 * Visible en local y pruebas; en cualquier otro entorno, solo para usuarios de
 * plataforma. Si no se cumple, 404 y no 403: no se revela que la ruta existe.
 * Nunca se indexa y ningún menú la enlaza.
 */
final class ShowComponentCatalogController
{
    public function __invoke(Request $request): Response
    {
        abort_unless(app()->environment('local', 'testing') || Gate::allows('platform.access'), 404);

        $response = Inertia::render('Components/Index')->toResponse($request);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
