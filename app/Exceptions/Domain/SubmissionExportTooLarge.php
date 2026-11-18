<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use DomainException;

/** La exportación supera el tope de filas; hay que afinar los filtros (plan 009, DA-3, CA-009-16). */
final class SubmissionExportTooLarge extends DomainException
{
    public const CODE = 'SUBMISSION_EXPORT_TOO_LARGE';

    public function __construct(public readonly int $rows, public readonly int $maxRows)
    {
        parent::__construct(__('submission_panel.export.too_large', ['rows' => $rows, 'max' => $maxRows]).' ('.self::CODE.')');
    }
}
