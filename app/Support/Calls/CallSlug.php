<?php

declare(strict_types=1);

namespace App\Support\Calls;

use App\Models\CallForSubmissions;
use App\Models\Event;
use Illuminate\Support\Str;

/**
 * Identificador de la convocatoria en la URL (plan 004, CA-004-28). Se genera a
 * partir del título, nunca lo captura el organizador. Es único dentro del evento
 * y también cuenta el de las convocatorias eliminadas, porque el índice único
 * las conserva. Se invoca dentro del contexto de la organización del evento.
 */
final class CallSlug
{
    private const MAX_LENGTH = 100;

    private const FALLBACK = 'convocatoria';

    /** @param  array<string, string>  $titles texto por idioma, ya sin vacíos */
    public static function for(Event $event, array $titles): string
    {
        $base = self::base($event, $titles);
        $candidate = $base;
        $suffix = 2;

        while (self::taken($event, $candidate)) {
            $tail = '-'.$suffix++;
            $candidate = Str::limit($base, self::MAX_LENGTH - strlen($tail), '').$tail;
        }

        return $candidate;
    }

    /** @param  array<string, string>  $titles */
    private static function base(Event $event, array $titles): string
    {
        $ordered = [$titles[$event->default_locale] ?? '', ...array_values($titles)];

        foreach ($ordered as $title) {
            $slug = trim(Str::limit(Str::slug($title), self::MAX_LENGTH, ''), '-');

            if ($slug !== '') {
                return $slug;
            }
        }

        return self::FALLBACK;
    }

    private static function taken(Event $event, string $slug): bool
    {
        return CallForSubmissions::withTrashed()
            ->where('event_id', $event->id)
            ->where('slug', $slug)
            ->exists();
    }
}
