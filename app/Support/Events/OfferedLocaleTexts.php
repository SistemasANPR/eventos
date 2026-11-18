<?php

declare(strict_types=1);

namespace App\Support\Events;

use App\Models\Event;

/**
 * Reduce un texto traducido a los idiomas que ofrece un evento (plan 022, DA-2). Si falta la
 * traducción de un idioma ofrecido usa la del idioma por defecto del evento, luego la española
 * y, al final, la primera que exista.
 */
final class OfferedLocaleTexts
{
    /**
     * @param  array<string, string>  $texts
     * @return array<string, string>
     */
    public static function for(array $texts, Event $event): array
    {
        $fallback = $texts[$event->default_locale] ?? $texts['es'] ?? (string) reset($texts);
        $result = [];

        foreach ($event->offeredLocales() as $locale) {
            $result[$locale] = $texts[$locale] ?? $fallback;
        }

        return $result;
    }
}
