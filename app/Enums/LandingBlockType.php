<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tipos de bloque de la landing de un evento: la lista blanca cerrada (plan 022, DA-3). Un tipo
 * fuera de esta lista no se guarda ni se renderiza. Los bloques `event_info`, `calls` y `tracks`
 * no guardan copia de datos: se leen del evento al mostrar la página.
 */
enum LandingBlockType: string
{
    case Hero = 'hero';
    case RichText = 'rich_text';
    case EventInfo = 'event_info';
    case Calls = 'calls';
    case Tracks = 'tracks';
    case Faq = 'faq';
    case ImageGallery = 'image_gallery';
    case Spacer = 'spacer';
    case Columns = 'columns';

    /** Bloques que se leen del evento al mostrar la página y no guardan copia. */
    public function readsFromEvent(): bool
    {
        return in_array($this, [self::EventInfo, self::Calls, self::Tracks], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
