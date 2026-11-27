<?php

declare(strict_types=1);

namespace App\Support\Submissions;

use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\Organization;
use App\Support\Calls\ResolveCallState;
use App\Support\Events\PublicPageAvailability;

/**
 * A dónde lleva «Regresar» en la página de pedir el enlace de acceso (plan 034, DA-7): a la convocatoria de la
 * propuesta. La página es del evento y no sabe de qué convocatoria viene quien la abre, así que la elige así:
 * la que indica `?call=` (solo si es una convocatoria pública de ESTE evento) o, si no hay indicación, la única
 * convocatoria pública del evento. Con varias y sin indicación, regresa a la página del evento. Si el evento no
 * tiene página pública (archivado), no hay a dónde regresar. Qué páginas existen lo decide
 * `PublicPageAvailability` (plan 032, CA-032-7). Requiere el contexto de organización.
 */
final class AccessLinkBackUrl
{
    public static function for(Event $event, Organization $organization, string $locale, ?string $callSlug = null): ?string
    {
        if (! PublicPageAvailability::isAvailable($event)) {
            return null;
        }

        $calls = CallForSubmissions::query()
            ->where('event_id', $event->id)
            ->get()
            ->filter(fn (CallForSubmissions $call): bool => ResolveCallState::for($call) !== null)
            ->values();

        $chosen = $callSlug === null ? null : $calls->firstWhere('slug', $callSlug);
        $chosen ??= $calls->count() === 1 ? $calls->first() : null;

        if ($chosen instanceof CallForSubmissions && PublicPageAvailability::isAvailable($event, $chosen)) {
            return route('calls.show', ['organization' => $organization->slug, 'event' => $event->slug, 'call' => $chosen->slug], false)."?lang={$locale}";
        }

        return PublicPageAvailability::eventUrl($event, $organization, $locale);
    }
}
