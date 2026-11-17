<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use DomainException;

/** La convocatoria no está efectivamente abierta en este instante (plan 006, CA-006-2 y CA-006-3). */
final class CallNotOpen extends DomainException
{
    public const CODE = 'CALL_NOT_OPEN';

    public function __construct()
    {
        parent::__construct(__('submission_form.call_not_open').' ('.self::CODE.')');
    }
}
