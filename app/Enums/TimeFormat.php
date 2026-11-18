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
}
