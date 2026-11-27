<?php

declare(strict_types=1);

namespace App\Support\Program\Agenda;

use App\Models\Event;
use Illuminate\Container\Attributes\Bind;

/**
 * Lo que la pantalla de la agenda pide al servidor (plan 031, F4). Solo lectura: no escribe nada y un error de base se
 * propaga (nunca devuelve una agenda vacía ante un fallo, CA-031-25). Se llama dentro de la organización del evento (el
 * filtro de organización del modelo lo exige) y NO autoriza: quien lo llama ya decidió que el usuario puede ver la agenda.
 */
#[Bind(AgendaDayBuilder::class)]
interface BuildsAgenda
{
    /**
     * La agenda de un día. Sin `$date`, o con un día que no está entre los del selector, se muestra el primer día del
     * programa (el primero con actividades programadas o, si no hay, el primer día del evento).
     */
    public function day(Event $event, ?string $date = null): AgendaDay;

    /**
     * Los choques de TODAS las actividades programadas del evento, por id de actividad: la misma definición de solape de
     * `DetectScheduleConflicts` (plan 031, DA-5). Lo usan la agenda y la lista (CA-031-20); una actividad sin choques no figura.
     *
     * @return array<string, list<AgendaClash>>
     */
    public function clashes(Event $event): array;
}
