<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Event;
use Closure;
use Filament\Schemas\Components\Component;
use LogicException;

/**
 * Un campo por cada idioma que ofrece el evento (plan 022): los demás idiomas no se crean, y el
 * idioma por defecto va primero. Sustituye a los recorridos de la lista completa de idiomas
 * de la plataforma en las pantallas. Quien arma el campo decide si es obligatorio con `$isDefault`.
 */
final class LocalizedFields
{
    /**
     * @param  Closure(string $locale, bool $isDefault): (Component|list<Component>)  $make
     * @return list<Component>
     */
    public static function for(Event $event, Closure $make): array
    {
        $fields = [];

        foreach ($event->offeredLocales() as $locale) {
            $made = $make($locale, $locale === $event->default_locale);

            foreach (is_array($made) ? $made : [$made] as $field) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /** El evento de una página anidada bajo él (Filament lo lee de la ruta y ya viene acotado por la organización). */
    public static function eventOf(mixed $livewire): Event
    {
        $event = is_object($livewire) && method_exists($livewire, 'getParentRecord') ? $livewire->getParentRecord() : null;

        return $event instanceof Event ? $event : throw new LogicException('La pantalla necesita un evento en la ruta.');
    }
}
