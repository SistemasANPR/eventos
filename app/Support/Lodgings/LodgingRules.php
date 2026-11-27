<?php

declare(strict_types=1);

namespace App\Support\Lodgings;

/** Reglas y limpieza de los datos de un hospedaje (plan 030, CA-030-2). Las usan las Actions de alta y edición. */
final class LodgingRules
{
    /** Campos de texto que se guardan recortados y, si quedan vacíos, como nulos. */
    public const TEXT_FIELDS = ['name', 'address', 'description', 'booking_url', 'rate'];

    /** Imágenes que admite la galería de un hospedaje (plan 030, DA-5). */
    public const MAX_IMAGES = 10;

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'address' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            // Solo http y https: nada de `javascript:` ni otros esquemas.
            'booking_url' => ['nullable', 'string', 'max:2048', 'url:http,https'],
            'rate' => ['nullable', 'string', 'max:160'],
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
     * Valores listos para guardar: solo las claves conocidas y el texto vacío como nulo (el nombre nunca).
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function forStorage(array $validated): array
    {
        $attributes = array_intersect_key($validated, array_flip([...self::TEXT_FIELDS, 'position']));

        foreach ($attributes as $field => $value) {
            if ($field !== 'name' && $value === '') {
                $attributes[$field] = null;
            }
        }

        return $attributes;
    }
}
