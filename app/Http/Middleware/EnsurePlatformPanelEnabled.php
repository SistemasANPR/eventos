<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Apaga el panel de plataforma entero (también sus peticiones de Livewire)
 * cuando `platform.enabled` es falso (plan 016, DA-1, CA-016-6): responde 404,
 * como si la ruta no existiera.
 */
final class EnsurePlatformPanelEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('platform.enabled'), 404);

        return $next($request);
    }
}
