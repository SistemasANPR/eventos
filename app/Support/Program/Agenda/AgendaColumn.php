<?php

declare(strict_types=1);

namespace App\Support\Program\Agenda;

/**
 * Una columna de la cuadrícula (plan 031, DA-7; plan 035, DA-9). `key` es el id de la sala, `external` o `none`;
 * `label` es el nombre de la sala o el texto fijo de las otras dos (lo traduce quien dibuja, no este objeto: aquí
 * llega vacío para `external` y `none`).
 */
final readonly class AgendaColumn
{
    /** @param  list<AgendaCell>  $cells  ordenadas por hora de inicio */
    public function __construct(
        public string $key,
        public AgendaColumnKind $kind,
        public ?string $label = null,
        public ?int $capacity = null,
        public array $cells = [],
    ) {}
}
