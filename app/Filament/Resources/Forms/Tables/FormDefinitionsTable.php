<?php

declare(strict_types=1);

namespace App\Filament\Resources\Forms\Tables;

use App\Enums\FormDefinitionStatus;
use App\Models\FormDefinition;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Lista de formularios de la organización (plan 005, H-005-1): nombre, evento, estado y número de campos. */
final class FormDefinitionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('forms.table.name'))
                    ->searchable()
                    ->weight('medium'),
                TextColumn::make('event.name')
                    ->label(__('forms.table.event'))
                    ->state(fn (FormDefinition $record): string => (string) $record->event?->getTranslation('name', $record->event->default_locale, true)),
                TextColumn::make('status')
                    ->label(__('forms.table.status'))
                    ->badge()
                    // El estado no se comunica solo con color (design.md §3.1): lleva icono y texto.
                    ->icon(fn (FormDefinitionStatus $state): Heroicon => match ($state) {
                        FormDefinitionStatus::Draft => Heroicon::OutlinedPencilSquare,
                        FormDefinitionStatus::Published => Heroicon::OutlinedCheckCircle,
                    })
                    ->formatStateUsing(fn (FormDefinitionStatus $state): string => __("forms.status.{$state->value}")),
                TextColumn::make('fields_count')
                    ->label(__('forms.table.fields'))
                    ->state(fn (FormDefinition $record): int => count($record->fields)),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([EditAction::make()])
            ->emptyStateHeading(__('forms.table.empty_heading'))
            ->emptyStateDescription(__('forms.table.empty_description'));
    }
}
