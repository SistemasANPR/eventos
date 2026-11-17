<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de las páginas del autor (plan 006, DA-3, CA-006-39). Las rutas con el token en la URL no
 * deben filtrarlo por `Referer` ni quedar en cachés del navegador o de intermediarios; las páginas con
 * la propuesta de una persona tampoco se guardan.
 */
final class ProtectAuthorPages
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
