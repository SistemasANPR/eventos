<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Estado que fija una persona (plan 004, DA-1). El estado público se calcula aparte: `CallState`. */
enum CallStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Published = 'published';
    case Paused = 'paused';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return __("calls.status.{$this->value}");
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Published => 'success',
            self::Paused => 'warning',
            self::Closed => 'danger',
        };
    }
}
