<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Support\Files\MediaDisk;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Logotipo del evento para el panel, también con el evento en borrador (plan 027, DA-4):
 * `GET /admin/{organization:slug}/events/{event}/logo`. Exige sesión y `update` sobre el evento
 * (`events.manage`). Quien no es miembro de la organización de la ruta, o pide un evento de otra
 * organización, recibe 404 sin saber si existe; un miembro sin el permiso, 403. Sin caché compartida.
 */
final class ShowEventLogoEditorController
{
    public function __invoke(Request $request, Organization $organization, Event $event): StreamedResponse
    {
        /** @var User $user `auth` garantiza la sesión */
        $user = $request->user();

        abort_unless($user->hasActiveMembershipIn($organization), 404);
        Gate::forUser($user)->authorize('update', $event);

        abort_unless(is_string($event->logo_path) && MediaDisk::get()->exists($event->logo_path), 404);

        return MediaDisk::get()->response($event->logo_path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
