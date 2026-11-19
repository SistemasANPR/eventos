<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Models\Event;
use App\Models\LandingImage;
use App\Models\Organization;
use App\Models\User;
use App\Support\Files\MediaDisk;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Imagen de la landing para el editor del panel, publicada o solo en borrador (plan 022, DA-7, CA-022-4, 8):
 * `GET /admin/{organization:slug}/events/{event}/landing/images/{image}`. Exige sesión y `viewLanding` sobre
 * el evento (`events.manage`). Quien no es miembro de la organización de la ruta, o pide un evento o una
 * imagen de otra organización o evento, recibe 404 sin saber si existen; un miembro sin el permiso, 403.
 * Sin caché compartida: lo del borrador no debe quedar en una caché pública.
 */
final class ShowLandingEditorImageController
{
    public function __invoke(Request $request, Organization $organization, Event $event, string $image): StreamedResponse
    {
        /** @var User $user `auth` garantiza la sesión */
        $user = $request->user();

        abort_unless($user->hasActiveMembershipIn($organization) && Str::isUuid($image), 404);
        Gate::forUser($user)->authorize('viewLanding', $event);

        $path = LandingImage::query()->where('event_id', $event->id)->whereKey($image)->value('path');

        abort_unless(is_string($path) && MediaDisk::get()->exists($path), 404);

        return MediaDisk::get()->response($path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
