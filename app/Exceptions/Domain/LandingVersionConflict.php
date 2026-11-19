<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use DomainException;

/**
 * Otra edición de la landing se guardó antes (bloqueo optimista, plan 022, CA-022-15): la `version` que
 * trae el editor ya no es la vigente. No se pisa lo guardado; el organizador recarga y vuelve a intentarlo.
 */
final class LandingVersionConflict extends DomainException
{
    public const CODE = 'LANDING_VERSION_CONFLICT';

    public function __construct()
    {
        parent::__construct(__('landings.errors.version_conflict').' ('.self::CODE.')');
    }
}
