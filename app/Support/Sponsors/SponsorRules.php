<?php

declare(strict_types=1);

namespace App\Support\Sponsors;

use App\Models\SponsorCategory;
use Closure;

/** Reglas y limpieza de los datos de un patrocinador (plan 030, CA-030-5, CA-030-11). Las usan las Actions de alta y edición. */
final class SponsorRules
{
    public const TEXT_FIELDS = ['name', 'website_url'];

    /** Campos que se guardan tal cual vienen en `$data` (el vacío pasa a nulo salvo el nombre). */
    private const STORED_FIELDS = ['name', 'website_url', 'sponsor_category_id', 'position'];

    /**
     * @param  string  $eventId  evento del patrocinador: su nivel (categoría) debe ser de ese mismo evento
     * @return array<string, list<mixed>>
     */
    public static function rules(string $eventId): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            // Solo http y https: nada de `javascript:` ni otros esquemas.
            'website_url' => ['nullable', 'string', 'max:2048', 'url:http,https'],
            'sponsor_category_id' => ['nullable', 'string', self::categoryOfEvent($eventId)],
            'position' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function trimmed(array $data): array
    {
        foreach (self::TEXT_FIELDS as $field) {
            if (array_key_exists($field, $data) && is_string($data[$field])) {
                $data[$field] = trim($data[$field]);
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function forStorage(array $validated): array
    {
        $attributes = array_intersect_key($validated, array_flip(self::STORED_FIELDS));

        foreach ($attributes as $field => $value) {
            if ($field !== 'name' && $value === '') {
                $attributes[$field] = null;
            }
        }

        return $attributes;
    }

    /** La categoría existe y es de este evento (la consulta ya pasa por el filtro de organización del modelo). */
    private static function categoryOfEvent(string $eventId): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($eventId): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            $exists = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1
                && SponsorCategory::query()->where('event_id', $eventId)->whereKey($value)->exists();

            if (! $exists) {
                $fail('sponsors.category_invalid')->translate();
            }
        };
    }
}
