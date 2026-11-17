<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use DomainException;

/** Otra persona cambió el formulario mientras se editaba; no se pisa su cambio (plan 005, CA-005-22). */
final class FormVersionConflict extends DomainException
{
    public const CODE = 'FORM_VERSION_CONFLICT';

    public function __construct()
    {
        parent::__construct('El formulario cambió mientras lo editabas; recarga la página (código '.self::CODE.').');
    }
}
