<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use Illuminate\Validation\ValidationException;

/**
 * El evento no tiene publicado su aviso de privacidad en algún idioma de la
 * convocatoria (plan 004, DA-6 y CA-004-10): sin un texto que consentir no se abre.
 * Es una `ValidationException` para que el panel la muestre como error del formulario.
 */
final class PrivacyNoticeMissing extends ValidationException
{
    public const CODE = 'PRIVACY_NOTICE_MISSING';

    /** @param  list<string>  $locales  idiomas de la convocatoria sin aviso publicado */
    public static function forLocales(array $locales): self
    {
        /** @var self $exception */
        $exception = self::withMessages([
            'status' => __('calls.privacy_notice_missing', ['locales' => implode(', ', $locales)]).' ('.self::CODE.')',
        ]);

        return $exception;
    }
}
