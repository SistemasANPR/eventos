<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\RelationManagers;

use App\Actions\Calls\ArchiveSubmissionType;
use App\Actions\Calls\CreateSubmissionType;
use App\Actions\Calls\MoveSubmissionType;
use App\Actions\Calls\RenameSubmissionType;
use App\Actions\Calls\RestoreSubmissionType;
use App\Enums\MoveDirection;
use App\Filament\Support\ActionErrors;
use App\Filament\Support\SubmissionTypeFields;
use App\Models\Event;
use App\Models\SubmissionType;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Tipos de propuesta DEL evento (plan 004, DA-7): lista ordenada, alta,
 * renombrar, subir, bajar, archivar y restaurar. Delgado: las reglas viven en
 * las Actions y las Policies deciden; no hay forma de borrar un tipo.
 */
class SubmissionTypesRelationManager extends RelationManager
{
    protected static string $relationship = 'submissionTypes';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('calls.types.relation_title');
    }

    /** Solo quien consulta o gestiona convocatorias ve la sección (el revisor no). */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('viewAny', SubmissionType::class);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('calls.types.columns.name'))
                    ->state(fn (SubmissionType $record): string => $record->label($this->owner()->default_locale))
                    ->weight('medium'),
                TextColumn::make('position')->label(__('calls.types.columns.position')),
                TextColumn::make('archived_at')
                    ->label(__('calls.types.columns.status'))
                    ->state(fn (SubmissionType $record): string => __($record->isArchived() ? 'calls.types.archived' : 'calls.types.active'))
                    ->badge()
                    ->color(fn (SubmissionType $record): string => $record->isArchived() ? 'gray' : 'success'),
            ])
            ->defaultSort('position')
            ->paginated(false)
            ->headerActions([$this->createAction()])
            ->recordActions([
                $this->moveAction('moveUp', MoveDirection::Up, Heroicon::OutlinedArrowUp),
                $this->moveAction('moveDown', MoveDirection::Down, Heroicon::OutlinedArrowDown),
                $this->renameAction(),
                $this->archiveAction(),
                $this->restoreAction(),
            ])
            ->emptyStateHeading(__('calls.types.empty_heading'));
    }

    private function createAction(): Action
    {
        return Action::make('createType')
            ->label(__('calls.type_create'))
            ->icon(Heroicon::OutlinedPlus)
            ->schema(fn (): array => SubmissionTypeFields::names($this->owner()))
            ->visible(fn (): bool => Gate::allows('create', [SubmissionType::class, $this->owner()]))
            ->action(fn (array $data) => $this->run(
                fn () => app(CreateSubmissionType::class)->handle(ActionErrors::actor(), $this->owner(), $data),
                __('calls.types.saved'),
            ));
    }

    private function renameAction(): Action
    {
        return Action::make('renameType')
            ->label(__('calls.types.rename'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->schema(fn (): array => SubmissionTypeFields::names($this->owner()))
            ->fillForm(fn (SubmissionType $record): array => ['name' => $record->getTranslations('name')])
            ->visible(fn (SubmissionType $record): bool => Gate::allows('update', $record))
            ->action(fn (SubmissionType $record, array $data) => $this->run(
                fn () => app(RenameSubmissionType::class)->handle(ActionErrors::actor(), $record, $data),
                __('calls.types.saved'),
            ));
    }

    private function moveAction(string $name, MoveDirection $direction, Heroicon $icon): Action
    {
        return Action::make($name)
            ->label(__("calls.types.{$direction->value}"))
            ->icon($icon)
            ->iconButton()
            ->visible(fn (SubmissionType $record): bool => Gate::allows('update', $record))
            ->action(fn (SubmissionType $record) => $this->run(
                fn () => app(MoveSubmissionType::class)->handle(ActionErrors::actor(), $record, $direction),
                __('calls.types.moved'),
            ));
    }

    private function archiveAction(): Action
    {
        return Action::make('archiveType')
            ->label(__('calls.types.archive'))
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(__('calls.types.archive_confirm'))
            ->visible(fn (SubmissionType $record): bool => ! $record->isArchived() && Gate::allows('update', $record))
            ->action(fn (SubmissionType $record) => $this->run(
                fn () => app(ArchiveSubmissionType::class)->handle(ActionErrors::actor(), $record),
                __('calls.types.archived_done'),
            ));
    }

    private function restoreAction(): Action
    {
        return Action::make('restoreType')
            ->label(__('calls.types.restore'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->visible(fn (SubmissionType $record): bool => $record->isArchived() && Gate::allows('update', $record))
            ->action(fn (SubmissionType $record) => $this->run(
                fn () => app(RestoreSubmissionType::class)->handle(ActionErrors::actor(), $record),
                __('calls.types.restored_done'),
            ));
    }

    private function owner(): Event
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof Event ? $owner : throw new \LogicException('El gestor de tipos de propuesta necesita un evento.');
    }

    /** @param  callable(): mixed  $operation */
    private function run(callable $operation, string $successTitle): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            Notification::make()
                ->title(__('calls.actions.failed'))
                ->body(ActionErrors::summary($exception))
                ->danger()
                ->send();

            return;
        }

        Notification::make()->title($successTitle)->success()->send();
    }
}
