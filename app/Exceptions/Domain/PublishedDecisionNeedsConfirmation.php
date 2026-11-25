<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use DomainException;

/**
 * Se intentó revertir una decisión cuyo resultado ya se publicó sin confirmar de forma explícita que el
 * autor ya fue avisado (plan 012, DA-4, CA-012-8). Nada cambia.
 */
final class PublishedDecisionNeedsConfirmation extends DomainException
{
    public const CODE = 'PUBLISHED_DECISION_NEEDS_CONFIRMATION';

    public function __construct()
    {
        parent::__construct(__('submission_decisions.errors.confirmation_required').' ('.self::CODE.')');
    }
}
