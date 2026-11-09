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
 * Middleware de tenant del panel de Filament (`->tenantMiddleware(..., isPersistent: true)`,
 * persistente para que corra también en las peticiones de Livewire). Toma la
 * organización del tenant de Filament, que ya pasó `canAccessTenant()`, y fija
 * `CurrentOrganization` (y con él el *team* de permisos) mientras dura la
 * petición. Es la única vía por la que el panel establece el contexto.
 */
final class ApplyOrganizationContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();

        abort_unless($tenant instanceof Organization, 404);

        return CurrentOrganization::run($tenant, fn (): Response => $next($request));
    }
}
