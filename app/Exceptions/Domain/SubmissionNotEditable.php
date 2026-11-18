<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use DomainException;

/**
 * La propuesta ya no se puede editar: está retirada o el equipo ya trabajó con ella, o no viene de una
 * convocatoria (plan 006, CA-006-48).
 */
final class SubmissionNotEditable extends DomainException
{
    public const CODE = 'SUBMISSION_NOT_EDITABLE';

    public function __construct()
    {
        parent::__construct(__('author.submission_not_editable').' ('.self::CODE.')');
    }
}
