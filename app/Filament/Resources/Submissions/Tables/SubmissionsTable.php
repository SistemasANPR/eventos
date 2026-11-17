<?php

declare(strict_types=1);

namespace App\Filament\Resources\Submissions\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Tabla de propuestas (plan 009). F0: solo el título; los filtros y columnas llegan en F1. */
final class SubmissionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label(__('submission_panel.columns.title')),
            ])
            ->emptyStateHeading(__('submission_panel.empty'));
    }
}
