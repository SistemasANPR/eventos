<?php

declare(strict_types=1);

namespace App\Support\Submissions;

/** Resultado de una corrida del recordatorio de cierre en una organización (plan 012, F3): enviados y fallidos. */
final readonly class ClosingRemindersReport
{
    public function __construct(
        public int $sent,
        public int $failed,
    ) {}
}
