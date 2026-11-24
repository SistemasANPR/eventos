<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use RuntimeException;

/**
 * No hay sal para la huella de las anonimizaciones (`ANONYMIZATION_SALT`). Sin ella no se anonimiza a
 * nadie: no se podría dejar el rastro que permite reaplicar la anonimización tras restaurar un respaldo.
 */
final class AnonymizationSaltMissing extends RuntimeException
{
    public const CODE = 'ANONYMIZATION_SALT_MISSING';

    public function __construct()
    {
        parent::__construct('Falta configurar ANONYMIZATION_SALT: sin ella no se puede anonimizar (código '.self::CODE.').');
    }
}
