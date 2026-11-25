<?php

declare(strict_types=1);

namespace App\Support\Program;

use App\Models\Event;
use App\Models\Organization;
use Illuminate\Http\Request;

/**
 * Direcciones relativas entre las páginas públicas del programa (plan 029, §4.1 y §4.3). Conservan
 * el idioma que el visitante pidió con `?lang=` y el evento ofrece; sin él, no se añade ningún
 * parámetro y manda el idioma por defecto del evento.
 */
final class PublicProgramLinks
{
    /** El idioma que el visitante pidió con `?lang=` y el evento ofrece: es el único que se conserva en los enlaces. */
    public static function explicitLanguage(Request $request, Event $event): ?string
    {
        $requested = $request->query('lang');

        return is_string($requested) && in_array($requested, $event->offeredLocales(), true) ? $requested : null;
    }

    /**
     * Enlaces entre las secciones del evento.
     *
     * @return array{programUrl: string, speakersUrl: string, eventUrl: string}
     */
    public static function nav(Organization $organization, Event $event, ?string $language): array
    {
        $parameters = [...self::parameters($organization, $event), ...self::languageQuery($language)];

        return [
            'programUrl' => route('events.program', $parameters, false),
            'speakersUrl' => route('events.speakers.index', $parameters, false),
            'eventUrl' => route('events.show', $parameters, false),
        ];
    }

    /** @return array{organization: string, event: string} */
    public static function parameters(Organization $organization, Event $event): array
    {
        return ['organization' => $organization->slug, 'event' => $event->slug];
    }

    /** @return array<string, string> */
    public static function languageQuery(?string $language): array
    {
        return $language === null ? [] : ['lang' => $language];
    }

    /** `?page=` solo cuenta si es un entero positivo; cualquier otro valor es la primera página. */
    public static function page(Request $request): int
    {
        $page = $request->query('page');

        return is_string($page) && ctype_digit($page) ? max(1, (int) $page) : 1;
    }
}
