<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use DomainException;

/**
 * Se quitó de un evento un idioma que aún usan una convocatoria o el aviso de privacidad publicado y falta
 * la confirmación (plan 026, CA-026-6, DA-1). Nada se borra: los textos de ese idioma quedan guardados.
 */
final class EventLocaleInUse extends DomainException
{
    public const CODE = 'EVENT_LOCALE_IN_USE';

    /**
     * @param  list<string>  $removedLocales  idiomas que se quitan
     * @param  list<string>  $affected  lo que los usa, ya descrito
     */
    public function __construct(public readonly array $removedLocales, public readonly array $affected)
    {
        parent::__construct(
            'Se quitan los idiomas ('.implode(', ', $removedLocales).') que usan: '.implode('; ', $affected)
            .'. Sus textos se conservan ocultos; confirma para guardar (código '.self::CODE.').',
        );
    }
}
