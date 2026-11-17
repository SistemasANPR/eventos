<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use DomainException;

/** Falta el formulario publicado o el aviso de privacidad vigente (plan 006, CA-006-11 y CA-006-53). */
final class CallNotReady extends DomainException
{
    public const CODE = 'CALL_NOT_READY';

    public function __construct()
    {
        parent::__construct(__('submission_form.call_not_ready').' ('.self::CODE.')');
    }
}
