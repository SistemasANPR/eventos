<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Auth\TwoFactorRequirement;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sustituye al middleware de Filament para la 2FA obligatoria (plan 014, CA-014-1): quien debe tener
 * verificación en dos pasos y aún no la confirmó va a configurarla y no ve ninguna página del
 * panel. Se decide en cada petición con `TwoFactorRequirement`, de modo que la bandera
 * `TWO_FACTOR_REQUIRED` se puede apagar sin volver a registrar las rutas (Filament evalúa
 * «¿es obligatoria?» al registrarlas, cuando todavía no hay usuario).
 */
final class EnsureTwoFactorConfirmed
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();

        if ($user instanceof User && ! TwoFactorRequirement::isSatisfiedBy($user)) {
            return redirect()->guest((string) Filament::getSetUpRequiredMultiFactorAuthenticationUrl());
        }

        return $next($request);
    }
}
