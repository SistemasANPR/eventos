<?php

declare(strict_types=1);

namespace App\Support\Privacy;

use App\Exceptions\Domain\AnonymizationSaltMissing;

/**
 * Cómo se reconoce a una persona en los datos sin guardar su correo (plan 014, DA-5).
 *
 * - `normalize()`: la forma única del correo (minúsculas y sin espacios en los extremos).
 * - `organizationHash()`: la huella del registro de anonimizaciones. HMAC-SHA256 con la sal de la
 *   plataforma más el identificador de la organización: no se puede revertir y la misma persona deja
 *   huellas distintas en organizaciones distintas.
 * - `dispatchHash()`: la huella que `SendNotification` guarda como `mail_dispatches.recipient_hash`
 *   (misma fórmula, para encontrar los envíos de una persona).
 */
final class PersonIdentity
{
    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** @throws AnonymizationSaltMissing sin `ANONYMIZATION_SALT` configurada */
    public static function organizationHash(string $email, string $organizationId): string
    {
        $salt = config('privacy.anonymization_salt');

        if (! is_string($salt) || $salt === '') {
            throw new AnonymizationSaltMissing;
        }

        return hash_hmac('sha256', self::normalize($email), $salt.'|'.$organizationId);
    }

    /** Debe seguir siendo idéntica a la de `SendNotification`. */
    public static function dispatchHash(string $email): string
    {
        return hash_hmac('sha256', self::normalize($email), (string) config('app.key'));
    }
}
