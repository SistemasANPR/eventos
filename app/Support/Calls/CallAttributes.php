<?php

declare(strict_types=1);

namespace App\Support\Calls;

use App\Models\Event;
use App\Support\Events\EventAttributes;

/**
 * Convierte los datos validados del formulario de una convocatoria en los
 * atributos del modelo: las fechas llegan como hora LOCAL de la zona del evento
 * y se guardan en UTC; los textos traducibles vacíos se descartan.
 */
final class CallAttributes
{
    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function fromValidated(array $validated, Event $event): array
    {
        $timezone = $event->timezone;

        return [
            'submission_type_id' => $validated['submission_type_id'],
            'title' => self::texts($validated['title'] ?? []),
            'description' => self::texts($validated['description'] ?? []),
            'locales' => array_values($validated['locales']),
            'opens_at' => EventAttributes::toUtc((string) $validated['opens_at'], $timezone),
            'closes_at' => EventAttributes::toUtc((string) $validated['closes_at'], $timezone),
            'results_at' => ($validated['results_at'] ?? null) === null || $validated['results_at'] === ''
                ? null
                : EventAttributes::toUtc((string) $validated['results_at'], $timezone),
        ];
    }

    /**
     * @param  array<string, mixed>  $texts
     * @return array<string, string>
     */
    public static function texts(array $texts): array
    {
        return array_filter(
            array_map(static fn (mixed $text): string => is_string($text) ? trim($text) : '', $texts),
            static fn (string $text): bool => $text !== '',
        );
    }
}
