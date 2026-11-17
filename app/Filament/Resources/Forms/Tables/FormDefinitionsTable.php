<?php

declare(strict_types=1);

namespace App\Filament\Resources\Forms\Tables;

use App\Actions\Forms\DuplicateFormDefinition;
use App\Enums\FormDefinitionStatus;
use App\Filament\Resources\Forms\FormDefinitionResource;
use App\Filament\Support\ActionErrors;
use App\Models\FormDefinition;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

/** Lista de formularios del evento (plan 005, H-005-1; plan 020): nombre, estado y número de campos. */
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
            ->recordActions([
                Action::make('preview')
                    ->label(__('forms.preview.action'))
                    ->icon(Heroicon::OutlinedEye)
                    ->url(fn (FormDefinition $record): string => FormDefinitionResource::getUrl('preview', ['event' => $record->event_id, 'record' => $record]))
                    ->visible(fn (FormDefinition $record): bool => auth()->user()?->can('view', $record) === true),
                Action::make('duplicate')
                    ->label(__('forms.actions.duplicate'))
                    ->icon(Heroicon::OutlinedDocumentDuplicate)
                    ->requiresConfirmation()
                    ->modalDescription(__('forms.actions.duplicate_help'))
                    ->visible(fn (): bool => auth()->user()?->can('create', FormDefinition::class) === true)
                    ->action(function (FormDefinition $record) {
                        try {
                            $copy = app(DuplicateFormDefinition::class)->handle(ActionErrors::actor(), $record);
                        } catch (ValidationException $exception) {
                            Notification::make()->danger()->title(ActionErrors::summary($exception))->send();

                            return null;
                        }

                        Notification::make()->success()->title(__('forms.actions.duplicated'))->send();

                        return redirect(FormDefinitionResource::getUrl('edit', ['event' => $copy->event_id, 'record' => $copy]));
                    }),
                EditAction::make()
                    ->url(fn (FormDefinition $record): string => FormDefinitionResource::getUrl('edit', ['event' => $record->event_id, 'record' => $record])),
            ])
            ->emptyStateHeading(__('forms.table.empty_heading'))
            ->emptyStateDescription(__('forms.table.empty_description'));
    }
}
