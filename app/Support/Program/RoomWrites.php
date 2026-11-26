<?php

declare(strict_types=1);

namespace App\Support\Program;

use App\Enums\RoomKind;

/**
 * Piezas que comparten el alta y la edición de un lugar del evento (plan 035, F2): los datos de entrada
 * se limpian antes de validar (espacios sobrantes, texto vacío como nulo) y se pasan a columnas solo
 * para las claves que llegaron, de modo que una edición que no menciona un campo lo conserva.
 */
final class RoomWrites
{
    /** Tope de la capacidad de un lugar (plan 035, CA-035-4): un estadio cabe; un valor absurdo, no. */
    public const MAX_CAPACITY = 100000;

    private const TEXT_FIELDS = ['name', 'address', 'map_url'];

    /**
     * Quita los espacios sobrantes y convierte en nulo el texto vacío de los campos opcionales.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> solo las claves conocidas que vinieron en los datos
     */
    public static function normalized(array $data): array
    {
        $clean = [];

        foreach (['name', 'kind', 'capacity', 'capacity_is_public', 'address', 'map_url'] as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];

            if (in_array($key, self::TEXT_FIELDS, true) && is_string($value)) {
                $value = trim($value);
            }

            if ($value === '' && in_array($key, ['capacity', 'address', 'map_url'], true)) {
                $value = null;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    /**
     * Columnas a guardar, ya validadas: solo las claves que vinieron (sin el nombre ni la posición).
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function attributes(array $validated): array
    {
        $attributes = [];

        if (array_key_exists('kind', $validated)) {
            $attributes['kind'] = RoomKind::from((string) $validated['kind'])->value;
        }

        if (array_key_exists('capacity', $validated)) {
            $attributes['capacity'] = $validated['capacity'] === null ? null : (int) $validated['capacity'];
        }

        if (array_key_exists('capacity_is_public', $validated)) {
            $attributes['capacity_is_public'] = filter_var($validated['capacity_is_public'], FILTER_VALIDATE_BOOLEAN);
        }

        foreach (['address', 'map_url'] as $key) {
            if (array_key_exists($key, $validated)) {
                $attributes[$key] = $validated[$key];
            }
        }

        return $attributes;
    }
}
