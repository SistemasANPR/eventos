<?php

declare(strict_types=1);

namespace App\Http\Controllers\Author;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Organization;
use App\Support\Events\PublicEventData;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Confirmación del enlace de acceso: `GET .../my-submission/access/{token}` (plan 006, DA-3, CA-006-34).
 * NO tiene efectos: no consulta ni consume el token, de modo que un escáner de correo que abre el enlace
 * no lo gasta, y la página no revela si el token es válido. Solo se consume con el POST del botón.
 */
final class ShowAccessConfirmationController
{
    public function __invoke(Request $request, Organization $organization, Event $event, string $token): Response
    {
        abort_if($event->status === EventStatus::Draft, 404);

        $locale = PublicEventData::resolveLocale($request, $event);
        app()->setLocale($locale);

        return Inertia::render('Author/ConfirmAccess', [
            'event' => ['name' => $event->getTranslation('name', $locale)],
            'organization' => ['name' => $organization->name, 'slug' => $organization->slug],
            'consumeUrl' => route('author.access.consume', [
                'organization' => $organization->slug,
                'event' => $event->slug,
                'token' => $token,
            ], false),
            'expiresInMinutes' => (int) config('submissions.author.session_minutes'),
        ]);
    }
}
