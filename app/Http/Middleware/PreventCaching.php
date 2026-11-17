<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Impide que navegadores y proxies guarden la respuesta (`Cache-Control: no-store`). Se usa en
 * las páginas que dependen de la sesión del visitante: el formulario público de propuestas
 * lleva claves emitidas a esa sesión y la confirmación no debe poder reabrirse desde la caché.
 */
final class PreventCaching
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
