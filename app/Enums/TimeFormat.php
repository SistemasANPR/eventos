<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Cómo se muestran las horas de un evento (plan 022). Lo guardado sigue en UTC o en `H:i:s`. */
enum TimeFormat: string implements HasLabel
{
    case TwelveHour = '12h';
    case TwentyFourHour = '24h';

    public function getLabel(): string
    {
        return __("events.time_format.options.{$this->value}");
    }

    /** Formato de PHP con el que un selector de hora MUESTRA la hora (el valor guardado no cambia). */
    public function displayTime(): string
    {
        return match ($this) {
            self::TwelveHour => 'g:i A',
            self::TwentyFourHour => 'H:i',
        };
    }

    /** Igual que `displayTime()`, para un selector de fecha y hora; `$dateFormat` es el de la fecha. */
    public function displayDateTime(string $dateFormat): string
    {
        return $dateFormat.' '.$this->displayTime();
    }
}
