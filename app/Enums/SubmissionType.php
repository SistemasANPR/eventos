<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SubmissionType: string implements HasLabel
{
    case Session = 'session';
    case Panel = 'panel';
    case Poster = 'poster';
    case Workshop = 'workshop';

    public function getLabel(): string
    {
        return __("calls.type.{$this->value}");
    }
}
