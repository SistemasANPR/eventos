<?php

declare(strict_types=1);

namespace App\Support\Program\Agenda;

/**
 * La agenda de lectura de UN día de un evento (plan 031, F4; DA-7 y DA-8): lo que dibuja la pantalla, ya resuelto por
 * el servidor. Fecha y hora son LOCALES del evento, sin zona (plan 021, DA-12).
 *
 * - `date`: el día mostrado (`Y-m-d`); `days`: los días que ofrece el selector (los días locales del evento y los de sus
 *   actividades, ordenados).
 * - `startMinute` y `endMinute`: el rango visible, en minutos desde las 00:00, múltiplos de `stepMinutes`: una hora antes
 *   de la primera actividad a una hora después de la última, con un mínimo de 08:00 a 18:00 si el día está vacío.
 * - `columns`: una por sala (en el orden de `position`, incluso sin actividades ese día), después «Fuera de sede» si hay
 *   algún lugar externo y siempre al final «Sin lugar».
 * - `pending`: las actividades sin programar del evento (no dependen del día).
 */
final readonly class AgendaDay
{
    /**
     * @param  list<string>  $days
     * @param  list<AgendaColumn>  $columns
     * @param  list<AgendaPending>  $pending
     */
    public function __construct(
        public string $date,
        public array $days,
        public int $startMinute,
        public int $endMinute,
        public array $columns,
        public array $pending = [],
        public int $stepMinutes = 15,
    ) {}
}
