<?php

declare(strict_types=1);

namespace App\Support\Program;

/**
 * Parte un nombre completo de una propuesta anterior al plan 021 (DA-9): la primera palabra es el
 * nombre y el resto el apellido. Es solo una sugerencia; el organizador la corrige al aprobar.
 */
final class PersonName
{
    /** @return array{first: string, last: string} */
    public static function split(string $fullName): array
    {
        if (preg_match('/^\s*(\S+)\s*(.*?)\s*$/su', $fullName, $parts) !== 1) {
            // Texto que no es UTF-8 válido: se conserva entero como nombre.
            return ['first' => trim($fullName), 'last' => ''];
        }

        return ['first' => $parts[1], 'last' => $parts[2]];
    }
}
