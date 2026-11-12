<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use DomainException;

/** Un cambio al formulario contradice las respuestas ya recibidas (plan 005, CA-005-19 y CA-005-20). */
final class FormChangeNotAllowed extends DomainException
{
    public const CODE = 'FORM_CHANGE_NOT_ALLOWED';

    private function __construct(string $reason)
    {
        parent::__construct($reason.' (código '.self::CODE.').');
    }

    public static function typeChanged(string $fieldKey): self
    {
        return new self("El campo «{$fieldKey}» tiene respuestas y no puede cambiar de tipo");
    }

    public static function requiredFieldAdded(string $fieldKey): self
    {
        return new self("Con respuestas recibidas, un campo nuevo debe ser opcional («{$fieldKey}»)");
    }

    public static function usedOptionRemoved(string $fieldKey): self
    {
        return new self("El campo «{$fieldKey}» tiene una opción ya elegida en respuestas y no se puede quitar");
    }
}
