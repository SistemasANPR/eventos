<?php

declare(strict_types=1);

namespace App\Support\Program\Agenda;

use App\Models\Event;

/**
 * PROVISIONAL (esqueleto del contrato, plan 031 F4): devuelve una agenda vacía para que la pantalla compile y se pruebe
 * mientras el carril de datos escribe la implementación real. El carril A lo REEMPLAZA entero.
 */
final class AgendaDayBuilder implements BuildsAgenda
{
    public function day(Event $event, ?string $date = null): AgendaDay
    {
        $day = $date ?? $event->starts_at->format('Y-m-d');

        return new AgendaDay(date: $day, days: [$day], startMinute: 8 * 60, endMinute: 18 * 60, columns: [new AgendaColumn('none', AgendaColumnKind::None)]);
    }

    public function clashes(Event $event): array
    {
        return [];
    }
}
