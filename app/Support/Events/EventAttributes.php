<?php

declare(strict_types=1);

namespace App\Support\Events;

use Carbon\CarbonImmutable;

/**
 * Convierte los datos validados del formulario en los atributos del modelo:
 * las fechas llegan como hora LOCAL del evento y se guardan en UTC (CA-003-4);
 * los textos traducibles vacíos se descartan.
 */
final class EventAttributes
{
    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function fromValidated(array $validated): array
    {
        $timezone = (string) $validated['timezone'];

        return [
            'name' => self::texts($validated['name'] ?? []),
            'description' => self::texts($validated['description'] ?? []),
            'slug' => $validated['slug'],
            'type' => $validated['type'],
            'starts_at' => self::toUtc((string) $validated['starts_at'], $timezone),
            'ends_at' => self::toUtc((string) $validated['ends_at'], $timezone),
            'timezone' => $timezone,
            'venue_name' => $validated['venue_name'] ?? null,
            'venue_address' => $validated['venue_address'] ?? null,
            'city' => $validated['city'] ?? null,
            'locales' => array_values($validated['locales']),
            'default_locale' => $validated['default_locale'],
            'currency' => $validated['currency'],
        ];
    }

    /** Hora local `Y-m-d H:i` de la zona del evento → instante en UTC. */
    public static function toUtc(string $local, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i', $local, $timezone)->utc();
    }

    /**
     * @param  array<string, mixed>  $texts
     * @return array<string, string>
     */
    private static function texts(array $texts): array
    {
        return array_filter(
            array_map(static fn (mixed $text): string => is_string($text) ? trim($text) : '', $texts),
            static fn (string $text): bool => $text !== '',
        );
    }
}
