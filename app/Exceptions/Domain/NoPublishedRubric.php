<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use DomainException;

/** El tipo de la propuesta no tiene una rúbrica publicada: no hay con qué evaluarla (plan 011, H-011-3). */
final class NoPublishedRubric extends DomainException
{
    public const CODE = 'NO_PUBLISHED_RUBRIC';

    public function __construct()
    {
        parent::__construct(__('reviewer.no_published_rubric').' ('.self::CODE.')');
    }
}
