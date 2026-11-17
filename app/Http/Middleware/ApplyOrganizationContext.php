<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware de tenant del panel de Filament (`->tenantMiddleware(..., isPersistent: true)`).
 * Toma la organización del tenant de Filament, que ya pasó `canAccessTenant()`,
 * y fija `CurrentOrganization` (y con él el *team* de permisos).
 *
 * IMPORTANTE: NO usa `CurrentOrganization::run()`. En una petición de Livewire
 * (cada envío de formulario o acción del panel), Filament ejecuta este
 * middleware en una cadena aparte ANTES de la petición real, con un destino
 * vacío; si el contexto se restaurara al volver de ahí, la petición real
 * llegaría sin organización y toda Policy la negaría. Por eso el contexto se
 * fija aquí y se limpia al terminar la petición.
 */
final class ApplyOrganizationContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();

        abort_unless($tenant instanceof Organization, 404);

        CurrentOrganization::set($tenant);
        app()->terminating(static fn () => CurrentOrganization::forget());

        return $next($request);
    }
}
