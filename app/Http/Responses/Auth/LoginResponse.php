<?php

declare(strict_types=1);

namespace App\Http\Responses\Auth;

use App\Models\User;
use App\Support\Auth\PortalDestination;
use Filament\Auth\Http\Responses\Contracts\LoginResponse as LoginResponseContract;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;

/**
 * Respuesta del acceso único (plan 017): cada persona llega a su portal. Sustituye
 * a la de Filament, que siempre enviaba al inicio del panel actual. La URL
 * «intended» se consume siempre, se use o no, para que no quede una vieja en la sesión.
 */
final class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): RedirectResponse
    {
        $user = Filament::auth()->user();
        $intended = session()->pull('url.intended');

        return redirect()->to($user instanceof User
            ? PortalDestination::for($user, is_string($intended) ? $intended : null)
            : Filament::getUrl());
    }
}
