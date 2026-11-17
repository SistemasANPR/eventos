<?php

declare(strict_types=1);

namespace App\Enums;

/** Estado de un formulario: borrador mientras se edita, publicado cuando una convocatoria lo usa (plan 005). */
enum FormDefinitionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
