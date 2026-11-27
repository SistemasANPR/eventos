<?php

declare(strict_types=1);

namespace App\Support\Program\Agenda;

/**
 * Una actividad dibujada en la cuadrícula de un día (plan 031, CA-031-19, 20 y 23). Los minutos cuentan desde las
 * 00:00 LOCALES del evento (sin zona) del día que se muestra y están ya recortados a ese día (0 a 1440): una actividad
 * que cruza la medianoche termina en 1440 y `continuesNextDay` es verdadero.
 *
 * `lane` y `lanes` reparten la columna entre actividades que se cruzan (solo ocurre en «Fuera de sede» y en una sala
 * con un choque preexistente): la tarjeta ocupa 1/`lanes` del ancho y se coloca en el carril `lane` (desde 0).
 */
final readonly class AgendaCell
{
    /**
     * @param  list<string>  $speakers  nombres para mostrar, en el orden asignado
     * @param  list<AgendaClash>  $clashes  vacío si no choca con nada
     */
    public function __construct(
        public string $activityId,
        public string $title,
        public int $startMinute,
        public int $endMinute,
        public int $lane = 0,
        public int $lanes = 1,
        public bool $continuesNextDay = false,
        public bool $isMultiDay = false,
        public bool $isPublished = true,
        public bool $isHiddenByReversal = false,
        public ?string $placeName = null,
        public ?int $quota = null,
        public array $speakers = [],
        public array $clashes = [],
    ) {}
}
