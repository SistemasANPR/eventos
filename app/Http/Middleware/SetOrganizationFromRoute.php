<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establece la organización en contexto desde `/{organization:slug}/...`
 * ANTES del binding de modelos con tenant (se registra con prioridad sobre
 * `SubstituteBindings`) y la limpia al terminar, con éxito o con excepción,
 * para que la siguiente petición del mismo proceso no la herede (CA-001-9/10).
 */
final class SetOrganizationFromRoute
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $slug = $route?->parameter('organization');

        if (! is_string($slug)) {
            abort(404);
        }

        $organization = Organization::query()->where('slug', $slug)->firstOrFail();

        $route->setParameter('organization', $organization);

        return CurrentOrganization::run($organization, fn (): Response => $next($request));
    }
}
