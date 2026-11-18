<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use DomainException;

/** El archivo de la exportación ya no existe (venció a las 24 horas) o no se pudo escribir (plan 009, CA-009-19). */
final class SubmissionExportUnavailable extends DomainException
{
    public const CODE = 'SUBMISSION_EXPORT_UNAVAILABLE';

    public function __construct()
    {
        parent::__construct(__('submission_panel.export.unavailable').' ('.self::CODE.')');
    }
}
