<?php

declare(strict_types=1);

namespace App\Support\Events;

use App\Enums\CallStatus;
use App\Enums\EventStatus;
use App\Enums\PublicPageUnavailability;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\Organization;

/**
 * La regla única de la página pública (plan 032, CA-032-7): dado un evento, y opcionalmente una de sus
 * convocatorias, dice si hay página pública y, si no, por qué. La usan el panel (qué enlaces ofrece) y el
 * sitio público (`EnsureEventIsAvailable`): una sola respuesta para ambos, para que no se contradigan.
 *
 * Solo cuenta lo que la persona fijó: una convocatoria pausada, cerrada o aún no abierta de un evento
 * publicado sí tiene página (con su estado); solo el borrador no.
 */
final class PublicPageAvailability
{
    /** @return PublicPageUnavailability|null el motivo, o `null` si la página existe */
    public static function reasonFor(Event $event, ?CallForSubmissions $call = null): ?PublicPageUnavailability
    {
        return match ($event->status) {
            EventStatus::Archived => PublicPageUnavailability::Archived,
            EventStatus::Draft => PublicPageUnavailability::DraftEvent,
            EventStatus::Published => $call?->status === CallStatus::Draft ? PublicPageUnavailability::DraftCall : null,
        };
    }

    /**
     * Dirección relativa de la página pública del evento, o `null` si no la hay: un enlace muerto no se
     * ofrece (CA-032-6). Con `$locale`, abre la página en ese idioma.
     */
    public static function eventUrl(Event $event, Organization $organization, ?string $locale = null): ?string
    {
        if (! self::isAvailable($event)) {
            return null;
        }

        $url = route('events.show', ['organization' => $organization->slug, 'event' => $event->slug], false);

        return $locale === null ? $url : "{$url}?lang={$locale}";
    }

    public static function isAvailable(Event $event, ?CallForSubmissions $call = null): bool
    {
        return self::reasonFor($event, $call) === null;
    }
}
