<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\Pages;

use App\Enums\ProgramCategoryKind;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/** Categorías de actividades del evento (plan 021, F1): el catálogo de `ActivityCategory`. */
class ManageEventActivityCategories extends ManageEventProgramCategories
{
    protected static string $relationship = 'activityCategories';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static function kind(): ProgramCategoryKind
    {
        return ProgramCategoryKind::Activity;
    }
}
