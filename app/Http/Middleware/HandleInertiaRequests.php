<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Inertia solo para el sitio público (ADR-0004): se aplica al grupo de rutas
 * públicas, no a Filament. Comparte el idioma de la petición y los textos de
 * `lang/{idioma}/public.php`: `lang/` es la única fuente de claves y el cliente
 * no mantiene una copia (resources/js/CLAUDE.md §6).
 */
class HandleInertiaRequests extends Middleware
{
    /** Plantilla raíz de la primera visita. */
    protected $rootView = 'app';

    /**
     * Son closures a propósito: Inertia las evalúa al renderizar, DESPUÉS de que
     * el controlador fijó el idioma de la página. Con valores directos se
     * calcularían antes y llegarían siempre en el idioma por defecto.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'locale' => static fn (): string => app()->getLocale(),
            'translations' => static fn (): array => app('translator')->getLoader()->load(app()->getLocale(), 'public'),
        ];
    }
}
