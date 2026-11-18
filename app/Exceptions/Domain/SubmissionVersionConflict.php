<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use DomainException;

/**
 * Otra edición se guardó antes (bloqueo optimista, plan 006, CA-006-43): la `version` que trae la petición
 * ya no es la vigente. No se pisa lo guardado; el autor recarga y vuelve a intentarlo.
 */
final class SubmissionVersionConflict extends DomainException
{
    public const CODE = 'SUBMISSION_VERSION_CONFLICT';

    public function __construct()
    {
        parent::__construct(__('author.submission_version_conflict').' ('.self::CODE.')');
    }
}
