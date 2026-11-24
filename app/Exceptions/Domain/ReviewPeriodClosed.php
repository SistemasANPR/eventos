<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use DomainException;

/** El periodo de evaluación del evento está cerrado: el evaluador ya no puede crear ni cambiar evaluaciones (plan 011, CA-011-26). */
final class ReviewPeriodClosed extends DomainException
{
    public const CODE = 'REVIEW_PERIOD_CLOSED';

    public function __construct()
    {
        parent::__construct(__('reviewer.period_closed_error').' ('.self::CODE.')');
    }
}
