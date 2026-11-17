<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Solo pasa quien tiene una membresía activa en la organización en contexto
 * (CA-001-22 y CA-001-24). Va después de `SetOrganizationFromRoute`. Una sesión
 * abierta de una persona desactivada queda sin acceso en la siguiente petición.
 * Sin sesión o sin membresía, 403: ocultar el menú no es autorizar.
 */
final class EnsureActiveMembership
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User && $user->hasActiveMembershipIn(CurrentOrganization::get()),
            403,
        );

        return $next($request);
    }
}
