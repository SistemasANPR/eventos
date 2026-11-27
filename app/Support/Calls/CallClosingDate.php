<?php

declare(strict_types=1);

namespace App\Support\Calls;

use App\Models\CallForSubmissions;
use App\Models\Event;
use Carbon\CarbonImmutable;

/**
 * Fecha y hora del cierre de una convocatoria para los correos al autor: en la zona del evento y en el
 * idioma indicado (formato `submission_results.closing_date_format`). La comparten el recordatorio de
 * cierre y el acuse de recibo, que dicen hasta cuándo se puede editar la propuesta.
 */
final class CallClosingDate
{
    public static function for(CallForSubmissions $call, Event $event, string $locale): string
    {
        return CarbonImmutable::instance($call->closes_at)
            ->setTimezone($event->timezone)
            ->locale($locale)
            ->translatedFormat(__('submission_results.closing_date_format', [], $locale));
    }
}
