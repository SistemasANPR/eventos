<?php

declare(strict_types=1);

namespace App\Support\Privacy;

/**
 * Quita de un texto (o de una estructura JSON) las cadenas que identifican a una persona: su correo y su
 * nombre completo. Sin distinguir mayúsculas. Es la defensa para el texto libre (respuestas del formulario,
 * comentarios, auditoría), donde el dato personal no está en una columna propia. No puede encontrar un dato
 * que la persona escribió sin su nombre ni su correo (un teléfono, por ejemplo): ese límite se declara en
 * `docs/privacidad.md` §6.
 */
final class Redactor
{
    /** Lo que sustituye a un texto o a una cadena personal. */
    public const TEXT = '[anonimizado]';

    /** Un nombre más corto que esto se confundiría con parte de una palabra común. */
    private const MIN_NEEDLE_LENGTH = 4;

    /**
     * Las cadenas a buscar, sin repetir, de la más larga a la más corta (para que un nombre completo se
     * sustituya antes que su apellido suelto).
     *
     * @param  iterable<string|null>  $candidates
     * @return list<string>
     */
    public static function needles(iterable $candidates): array
    {
        $needles = [];

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);

            if (mb_strlen($candidate) >= self::MIN_NEEDLE_LENGTH) {
                $needles[mb_strtolower($candidate)] = $candidate;
            }
        }

        $needles = array_values($needles);
        usort($needles, static fn (string $first, string $second): int => mb_strlen($second) <=> mb_strlen($first));

        return $needles;
    }

    /** @param  list<string>  $needles */
    public static function text(string $value, array $needles): string
    {
        foreach ($needles as $needle) {
            $value = (string) preg_replace('/'.preg_quote($needle, '/').'/iu', self::TEXT, $value);
        }

        return $value;
    }

    /**
     * @param  list<string>  $needles
     */
    public static function deep(mixed $value, array $needles): mixed
    {
        if (is_string($value)) {
            return self::text($value, $needles);
        }

        if (is_array($value)) {
            return array_map(static fn (mixed $item): mixed => self::deep($item, $needles), $value);
        }

        return $value;
    }

    /**
     * ¿Alguna de las cadenas aparece en el texto?
     *
     * @param  list<string>  $needles
     */
    public static function contains(string $value, array $needles): bool
    {
        return self::text($value, $needles) !== $value;
    }
}
