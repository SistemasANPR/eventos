<?php

declare(strict_types=1);

namespace App\Support\Program\Agenda;

/**
 * Un choque de una actividad con otra (plan 031, CA-031-20): la actividad contraria, para nombrarla en la insignia y
 * en el detalle, y, si es de ponente, el nombre del ponente repetido. Solo identificadores y textos del programa.
 */
final readonly class AgendaClash
{
    public function __construct(
        public AgendaClashType $type,
        public string $withActivityId,
        public string $withTitle,
        public ?string $speaker = null,
    ) {}
}
