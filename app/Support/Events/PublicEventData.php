<?php

declare(strict_types=1);

namespace App\Support\Events;

use App\Models\Event;
use Illuminate\Http\Request;

/**
 * Lo único que la página pública de un evento recibe (CA-002-15): campos
 * explícitos, sin identificadores internos, sin correos del equipo y sin datos
 * de otras organizaciones. Las fechas viajan en UTC (ISO 8601) con la zona del
 * evento; el cliente las formatea con `Intl`.
 */
final class PublicEventData
{
    /** @return array{name: string, description: ?string, startsAt: string, endsAt: string, timezone: string, venueName: ?string, venueAddress: ?string, city: ?string, locale: string, locales: list<string>} */
    public static function from(Event $event, string $locale): array
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
            'locale' => $locale,
            'locales' => $event->locales,
        ];
    }

    /**
     * Idioma de la página: `?lang=` si el evento lo ofrece; si no, el mejor
     * según `Accept-Language` entre los idiomas del evento; si no, el idioma
     * por defecto del evento.
     */
    public static function resolveLocale(Request $request, Event $event): string
    {
        $requested = $request->query('lang');

        if (is_string($requested) && in_array($requested, $event->locales, true)) {
            return $requested;
        }

        return $request->getPreferredLanguage($event->locales) ?? $event->default_locale;
    }
}
