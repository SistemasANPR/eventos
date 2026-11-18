<?php

declare(strict_types=1);

namespace App\Filament\Platform\Support;

use App\Models\Activity;

/**
 * Texto de lo que cambió un registro de auditoría, para el visor de plataforma
 * (plan 016, CA-016-27). Es una lista blanca: solo `reason`, `model`, `panel`,
 * `old` y `new`. Los registros de acceso (`auth`) no muestran nada, porque sus
 * propiedades llevan la IP, el hash del correo y la huella del dispositivo.
 */
final class AuditChanges
{
    private const SHOWN = ['reason', 'model', 'panel'];

    public static function describe(Activity $entry): string
    {
        if ($entry->log_name === 'auth') {
            return '';
        }

        $properties = $entry->properties?->all() ?? [];
        $parts = [];

        foreach (self::SHOWN as $key) {
            if (isset($properties[$key]) && is_string($properties[$key])) {
                $parts[] = "{$key}: {$properties[$key]}";
            }
        }

        $old = is_array($properties['old'] ?? null) ? $properties['old'] : [];
        $new = is_array($properties['new'] ?? null) ? $properties['new'] : [];

        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $field) {
            $parts[] = "{$field}: ".self::printable($old[$field] ?? null).' → '.self::printable($new[$field] ?? null);
        }

        return implode(' · ', $parts);
    }

    private static function printable(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE),
        };
    }
}
