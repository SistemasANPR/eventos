<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use DomainException;

/** La clave de idempotencia ya se usó con otra carga; responde 409 y no cambia nada (plan 006, CA-006-15). */
final class IdempotencyPayloadMismatch extends DomainException
{
    public const CODE = 'IDEMPOTENCY_PAYLOAD_MISMATCH';

    public function __construct()
    {
        parent::__construct(__('submission_form.idempotency_conflict').' ('.self::CODE.')');
    }
}
