<?php

declare(strict_types=1);

namespace App\Support\Events;

use App\Models\Event;
use App\Models\Organization;
use Illuminate\Http\Request;

/**
 * Lo único que la página pública de un evento recibe (CA-002-15): campos
 * explícitos, sin identificadores internos, sin correos del equipo y sin datos
 * de otras organizaciones. Las fechas viajan en UTC (ISO 8601) con la zona del
 * evento; el cliente las formatea con `Intl`.
 */
final class PublicEventData
{
    /** @return array{name: string, description: ?string, startsAt: string, endsAt: string, timezone: string, venueName: ?string, venueAddress: ?string, city: ?string, timeFormat: string, locale: string, locales: list<string>, logoUrl: ?string} */
    public static function from(Event $event, Organization $organization, string $locale): array
    {
        return [
            'name' => $event->getTranslation('name', $locale),
            'description' => $event->getTranslation('description', $locale, false) ?: null,
            'startsAt' => $event->starts_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'endsAt' => $event->ends_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'timezone' => $event->timezone,
            'venueName' => $event->venue_name,
            'venueAddress' => $event->venue_address,
            'city' => $event->city,
            'timeFormat' => $event->time_format->value,
            'locale' => $locale,
            'locales' => $event->offeredLocales(),
            'logoUrl' => self::logoUrl($event, $organization),
        ];
    }

    /**
     * Dirección pública del logotipo (plan 027, CA-027-12) o `null` si el evento no tiene. Nunca la
     * ruta del disco; `v` solo cambia con el archivo, para que el navegador no muestre el anterior
     * durante la caché corta de la ruta.
     */
    public static function logoUrl(Event $event, Organization $organization): ?string
    {
        if ($event->logo_path === null) {
            return null;
        }

        return route('public.events.logo', [
            'organization' => $organization->slug,
            'event' => $event->slug,
            'v' => hash('crc32b', $event->logo_path),
        ], false);
    }

    /**
     * Idioma de la página: `?lang=` si el evento lo ofrece; si no, el mejor
     * según `Accept-Language` entre los idiomas del evento; si no, el idioma
     * por defecto del evento.
     */
    public static function resolveLocale(Request $request, Event $event): string
    {
        $requested = $request->query('lang');

        $offered = $event->offeredLocales();

        if (is_string($requested) && in_array($requested, $offered, true)) {
            return $requested;
        }

        return $request->getPreferredLanguage($offered) ?? $event->default_locale;
    }
}
