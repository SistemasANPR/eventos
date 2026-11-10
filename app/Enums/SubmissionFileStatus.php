<?php

declare(strict_types=1);

namespace App\Enums;

/** Estado de un archivo de propuesta (plan 007, DA-6): subido y aún sin propuesta, o ya ligado a ella. */
enum SubmissionFileStatus: string
{
    case Pending = 'pending';
    case Attached = 'attached';
}
