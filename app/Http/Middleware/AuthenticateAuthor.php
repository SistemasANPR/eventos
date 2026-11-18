<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Event;
use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige una sesión de autor vigente (guard `author`, plan 006, CA-006-37 y CA-006-38). Sin ella, el
 * visitante va a pedir un enlace nuevo. Un usuario del panel (guard `web`) NO cuenta como autor.
 * Se aplica después de `SetOrganizationFromRoute` y del binding, que dejan la organización y el evento.
 */
final class AuthenticateAuthor
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard('author')->guest()) {
            $organization = $request->route('organization');
            $event = $request->route('event');

            abort_unless($organization instanceof Organization && $event instanceof Event, 404);

            return redirect()->route('author.request-link.show', ['organization' => $organization->slug, 'event' => $event->slug]);
        }

        return $next($request);
    }
}
