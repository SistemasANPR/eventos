<?php

declare(strict_types=1);

namespace App\Enums;

/** Estado de una versión de rúbrica (plan 011, H-011-1): se edita en borrador y se usa publicada. */
enum RubricStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
