<?php

declare(strict_types=1);

namespace App\Support\Program\Agenda;

/** Una actividad sin programar, para el panel lateral de pendientes (plan 031, DA-8). */
final readonly class AgendaPending
{
    /** @param  list<string>  $speakers */
    public function __construct(
        public string $activityId,
        public string $title,
        public bool $isPublished = true,
        public array $speakers = [],
    ) {}
}
