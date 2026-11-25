<?php

declare(strict_types=1);

namespace App\Support\Sponsors;

/** Reglas y limpieza de los datos de un patrocinador (plan 030, CA-030-5). Las usan las Actions de alta y edición. */
final class SponsorRules
{
    public const TEXT_FIELDS = ['name', 'level', 'website_url'];

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'level' => ['nullable', 'string', 'max:80'],
            // Solo http y https: nada de `javascript:` ni otros esquemas.
            'website_url' => ['nullable', 'string', 'max:2048', 'url:http,https'],
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
        $attributes = array_intersect_key($validated, array_flip([...self::TEXT_FIELDS, 'position']));

        foreach ($attributes as $field => $value) {
            if ($field !== 'name' && $value === '') {
                $attributes[$field] = null;
            }
        }

        return $attributes;
    }
}
