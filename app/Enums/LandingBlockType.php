<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tipos de bloque de la landing: la lista blanca cerrada (plan 022, DA-3; plan 033). Un tipo fuera de esta
 * lista no se guarda ni se renderiza. Los bloques `event_info`, `calls` y `tracks` no guardan copia de datos:
 * se leen del evento al mostrar la página. Los bloques `call_*` (plan 033) son las secciones de la landing de
 * una CONVOCATORIA: solo se admiten en la landing de una convocatoria y leen sus fechas, ejes, formulario y
 * demás convocatorias al mostrar la página; guardan solo los textos que el organizador personaliza.
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
    case CallHero = 'call_hero';
    case CallDates = 'call_dates';
    case CallAxes = 'call_axes';
    case CallRequirements = 'call_requirements';
    case CallSteps = 'call_steps';
    case CallPromos = 'call_promos';

    /** Bloques que se leen del evento o de la convocatoria al mostrar la página y no guardan copia. */
    public function readsFromEvent(): bool
    {
        return in_array($this, [self::EventInfo, self::Calls, self::Tracks, self::CallDates, self::CallAxes, self::CallRequirements, self::CallPromos], true);
    }

    /** ¿Es una sección propia de la landing de una convocatoria? */
    public function isForCall(): bool
    {
        return str_starts_with($this->value, 'call_');
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
