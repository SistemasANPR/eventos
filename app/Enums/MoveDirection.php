<?php

declare(strict_types=1);

namespace App\Enums;

/** Sentido en que se mueve un elemento dentro de una lista ordenada (tipos de propuesta, ejes). */
enum MoveDirection: string
{
    case Up = 'up';
    case Down = 'down';
}
