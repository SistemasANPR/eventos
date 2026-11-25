<?php

declare(strict_types=1);

namespace App\Support\Sponsors;

use App\Models\SponsorCategory;
use Closure;

/**
 * Reglas del nombre de una categoría de patrocinador (plan 030, CA-030-12), como las de las categorías de
 * ponentes: obligatorio, de hasta 100 caracteres y único en el evento sin distinguir mayúsculas. Las usan las
 * Actions; la consulta corre en la organización del evento (filtro del modelo).
 */
final class SponsorCategoryRules
{
    /**
     * @param  string|null  $ignoreId  categoría que se edita: no choca consigo misma
     * @return array<string, list<mixed>>
     */
    public static function rulesFor(string $eventId, ?string $ignoreId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:100', self::uniqueInEvent($eventId, $ignoreId)],
        ];
    }

    private static function uniqueInEvent(string $eventId, ?string $ignoreId): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($eventId, $ignoreId): void {
            if (! is_string($value)) {
                return;
            }

            // `lower()` en ambos lados: la misma función que el índice único de la base.
            $taken = SponsorCategory::query()
                ->where('event_id', $eventId)
                ->whereRaw('lower(name) = lower(?)', [$value])
                ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
                ->exists();

            if ($taken) {
                $fail('sponsors.categories.name_taken')->translate();
            }
        };
    }
}
