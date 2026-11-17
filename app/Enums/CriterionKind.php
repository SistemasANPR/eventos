<?php

declare(strict_types=1);

namespace App\Enums;

/** Tipo de criterio de una rúbrica (plan 011): calificación numérica o comentario libre. */
enum CriterionKind: string
{
    case Scale = 'scale';
    case Text = 'text';
}
