<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum OrganizationStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Suspended = 'suspended';

    public function getLabel(): string
    {
        return __("platform.status.{$this->value}");
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Suspended => 'danger',
        };
    }
}
