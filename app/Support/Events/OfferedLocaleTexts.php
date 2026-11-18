<?php

declare(strict_types=1);

namespace App\Support\Events;

use App\Http\Requests\Events\StoreEventRequest;
use App\Models\Event;

/**
 * Reduce un texto traducido a los idiomas que ofrece un evento (plan 026, DA-2). Si falta la
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

    /** Regla `array:` que solo admite las claves de los idiomas que ofrece el evento (plan 026, CA-026-4). */
    public static function arrayRule(Event $event): string
    {
        return 'array:'.implode(',', $event->offeredLocales());
    }

    /**
     * Quita de lo que llega del cliente los textos de idiomas del sistema que el evento no ofrece (plan 026,
     * DA-5, CA-026-3): un formulario viejo, abierto antes de quitar un idioma, no falla. Una clave que no es de
     * ningún idioma del sistema se deja para que la validación la rechace (CA-026-4).
     *
     * @param  array<array-key, mixed>  $texts
     * @return array<array-key, mixed>
     */
    public static function discardUnoffered(array $texts, Event $event): array
    {
        $offered = $event->offeredLocales();

        return array_filter(
            $texts,
            static fn (string|int $locale): bool => ! in_array($locale, StoreEventRequest::LOCALES, true) || in_array($locale, $offered, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * `discardUnoffered` aplicado a los textos que están bajo las claves indicadas de `$data`.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public static function discardIn(array $data, array $keys, Event $event): array
    {
        foreach ($keys as $key) {
            if (is_array($data[$key] ?? null)) {
                $data[$key] = self::discardUnoffered($data[$key], $event);
            }
        }

        return $data;
    }

    /**
     * `discardUnoffered` aplicado al texto `$key` de cada elemento de una lista (p. ej. la etiqueta de cada criterio).
     *
     * @param  array<array-key, mixed>  $items
     * @return array<array-key, mixed>
     */
    public static function discardInEach(array $items, string $key, Event $event): array
    {
        foreach ($items as $index => $item) {
            if (is_array($item)) {
                $items[$index] = self::discardIn($item, [$key], $event);
            }
        }

        return $items;
    }

    /**
     * Los textos guardados de idiomas que el evento ya no ofrece (plan 026, DA-1): el formulario del panel
     * no los trae, y al guardar se funden de vuelta para no perderlos (CA-026-7 y CA-026-8).
     *
     * @param  array<array-key, mixed>  $stored
     * @return array<string, string>
     */
    public static function unofferedOf(array $stored, Event $event): array
    {
        $offered = $event->offeredLocales();
        $kept = [];

        foreach ($stored as $locale => $text) {
            if (is_string($locale) && is_string($text) && ! in_array($locale, $offered, true)) {
                $kept[$locale] = $text;
            }
        }

        return $kept;
    }

    /**
     * Lo que llega del cliente (idiomas ofrecidos) más lo guardado de los idiomas no ofrecidos.
     *
     * @param  array<string, string>  $incoming
     * @param  array<array-key, mixed>  $stored
     * @return array<string, string>
     */
    public static function withUnoffered(array $incoming, array $stored, Event $event): array
    {
        return [...self::unofferedOf($stored, $event), ...$incoming];
    }
}
