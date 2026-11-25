<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Enums\SubmissionStatus;
use DomainException;

/**
 * El cambio de estado pedido no está en la tabla de transiciones (plan 012, DA-3, CA-012-4): por ejemplo
 * `accepted` → `rejected` directa o cualquier salida de `withdrawn`. El estado no cambia.
 */
final class InvalidSubmissionTransition extends DomainException
{
    public const CODE = 'INVALID_SUBMISSION_TRANSITION';

    public function __construct(public readonly SubmissionStatus $from, public readonly SubmissionStatus $to)
    {
        parent::__construct(__('submission_decisions.errors.invalid_transition', [
            'from' => $from->getLabel(),
            'to' => $to->getLabel(),
        ]).' ('.self::CODE.')');
    }
}
