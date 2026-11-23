<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use DomainException;

/**
 * Otra persona cambió el estado de la propuesta antes (control de concurrencia, plan 012, CA-012-6): el
 * estado con que quien decide cargó la pantalla ya no es el vigente. No se pisa nada; se recarga y se revisa.
 */
final class SubmissionStatusConflict extends DomainException
{
    public const CODE = 'SUBMISSION_STATUS_CONFLICT';

    public function __construct()
    {
        parent::__construct(__('submission_decisions.errors.status_conflict').' ('.self::CODE.')');
    }
}
