<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tipos de campo de un formulario de propuestas (plan 005, lista blanca). Cualquier
 * otro valor se rechaza al guardar: el tipo decide qué reglas de validación se arman.
 */
enum FieldType: string
{
    case ShortText = 'short_text';
    case LongText = 'long_text';
    case Number = 'number';
    case SingleChoice = 'single_choice';
    case MultipleChoice = 'multiple_choice';
    case Checkbox = 'checkbox';
    case Date = 'date';
    case Country = 'country';
    case File = 'file';

    public function hasOptions(): bool
    {
        return $this === self::SingleChoice || $this === self::MultipleChoice;
    }

    /** Solo los campos donde la persona escribe o sube algo pueden delatar a quien envía (DA-7). */
    public function canIdentifyAuthor(): bool
    {
        return $this === self::ShortText || $this === self::LongText || $this === self::File;
    }
}
