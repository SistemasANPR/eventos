<?php

declare(strict_types=1);

namespace App\Filament\Resources\Rubrics\Tables;

use App\Actions\Reviews\PublishRubric;
use App\Enums\RubricStatus;
use App\Filament\Resources\Rubrics\RubricResource;
use App\Filament\Support\ActionErrors;
use App\Models\Rubric;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Lista de rúbricas del evento (plan 011, F4b): tipo, versión, estado, revisión ciega y mínimo de evaluadores. */
final class RubricsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('submission_type')
                    ->label(__('rubrics.table.submission_type'))
                    ->state(fn (Rubric $record): string => $record->submissionType->label($record->event->default_locale))
                    ->weight('medium'),
                TextColumn::make('version')
                    ->label(__('rubrics.table.version'))
                    ->prefix('v'),
                TextColumn::make('status')
                    ->label(__('rubrics.table.status'))
                    ->badge()
                    // El estado no se comunica solo con color (design.md §3.1): lleva icono y texto.
                    ->icon(fn (RubricStatus $state): Heroicon => match ($state) {
                        RubricStatus::Draft => Heroicon::OutlinedPencilSquare,
                        RubricStatus::Published => Heroicon::OutlinedCheckCircle,
                    })
                    ->formatStateUsing(fn (RubricStatus $state): string => __("rubrics.status.{$state->value}")),
                IconColumn::make('blind_review')
                    ->label(__('rubrics.table.blind_review'))
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedEyeSlash)
                    ->falseIcon(Heroicon::OutlinedEye),
                TextColumn::make('min_reviewers')
                    ->label(__('rubrics.table.min_reviewers')),
                TextColumn::make('criteria_count')
                    ->label(__('rubrics.table.criteria')),
            ])
            // Sin N+1: el tipo, el evento y el número de criterios de toda la página se cargan de una vez.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['submissionType', 'event'])->withCount('criteria'))
            ->defaultSort('created_at', 'desc')
            ->paginationPageOptions([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->recordActions([
                self::publishAction(),
                Action::make('newVersion')
                    ->label(__('rubrics.actions.new_version'))
                    ->icon(Heroicon::OutlinedDocumentDuplicate)
                    ->color('gray')
                    ->visible(fn (Rubric $record): bool => $record->status === RubricStatus::Published && Gate::allows('create', [Rubric::class, $record->event]))
                    ->url(fn (Rubric $record): string => RubricResource::getUrl('create', ['event' => $record->event_id, 'from' => $record->id])),
                EditAction::make()
                    ->visible(fn (Rubric $record): bool => Gate::allows('update', $record))
                    ->url(fn (Rubric $record): string => RubricResource::getUrl('edit', ['event' => $record->event_id, 'record' => $record])),
            ])
            ->emptyStateHeading(__('rubrics.table.empty_heading'))
            ->emptyStateDescription(__('rubrics.table.empty_description'));
    }

    /** Publica un borrador; un fallo de validación se muestra como notificación y la rúbrica sigue en borrador. */
    private static function publishAction(): Action
    {
        return Action::make('publish')
            ->label(__('rubrics.actions.publish'))
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->requiresConfirmation()
            ->modalDescription(__('rubrics.actions.publish_help'))
            ->visible(fn (Rubric $record): bool => $record->isDraft() && Gate::allows('publish', $record))
            ->action(function (Rubric $record): void {
                try {
                    app(PublishRubric::class)->handle(ActionErrors::actor(), $record);
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title(ActionErrors::summary($exception))->send();

                    return;
                }

                Notification::make()->success()->title(__('rubrics.actions.published'))->send();
            });
    }
}
