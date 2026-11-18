<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\Pages;

use App\Enums\ProgramCategoryKind;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/** Categorías de ponentes del evento (plan 021, F1): el catálogo de `SpeakerCategory`. */
class ManageEventSpeakerCategories extends ManageEventProgramCategories
{
    protected static string $relationship = 'speakerCategories';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static function kind(): ProgramCategoryKind
    {
        return ProgramCategoryKind::Speaker;
    }
}
