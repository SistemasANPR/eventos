<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum EventType: string implements HasLabel
{
    case Congress = 'congress';
    case Forum = 'forum';
    case Workshop = 'workshop';
    case Webinar = 'webinar';
    case Expo = 'expo';

    public function getLabel(): string
    {
        return __("events.type.{$this->value}");
    }
}
