<?php

declare(strict_types=1);

namespace App\Support\Files;

/**
 * El `upload_token` liga las subidas a la sesión del formulario (plan 007 §4).
 * Solo se guarda su hash: el token en claro no se almacena ni se registra.
 */
final class UploadToken
{
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Comparación en tiempo constante entre un hash guardado y el token recibido. */
    public static function matches(string $storedHash, string $token): bool
    {
        return hash_equals($storedHash, self::hash($token));
    }
}
