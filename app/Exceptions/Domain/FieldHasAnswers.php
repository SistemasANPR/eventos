<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use DomainException;

/** Un campo de un formulario con respuestas no se elimina: se retira (plan 005, CA-005-18). */
final class FieldHasAnswers extends DomainException
{
    public const CODE = 'FIELD_HAS_ANSWERS';

    public function __construct(public readonly string $fieldKey)
    {
        parent::__construct("El campo «{$fieldKey}» tiene respuestas y no se puede eliminar; solo retirar (código ".self::CODE.').');
    }
}
