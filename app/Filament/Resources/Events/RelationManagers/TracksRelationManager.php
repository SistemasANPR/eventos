<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\RelationManagers;

use App\Actions\Calls\ArchiveTrack;
use App\Actions\Calls\CreateTrack;
use App\Actions\Calls\ReorderTracks;
use App\Actions\Calls\RestoreTrack;
use App\Actions\Calls\UpdateTrack;
use App\Enums\MoveDirection;
use App\Filament\Support\ActionErrors;
use App\Filament\Support\TrackFields;
use App\Models\Event;
use App\Models\Track;
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
 * Ejes temáticos DEL evento (plan 004, H-004-2): lista ordenada, alta, edición
 * por idioma, subir, bajar, archivar y restaurar. Delgado: las reglas viven en
 * las Actions y las Policies deciden; no hay forma de borrar un eje desde aquí.
 */
class TracksRelationManager extends RelationManager
{
    protected static string $relationship = 'tracks';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('calls.tracks.relation_title');
    }

    /** Solo quien consulta convocatorias o gestiona ejes ve la sección (el revisor no). */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('viewAny', Track::class);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('calls.tracks.columns.name'))
                    ->state(fn (Track $record): string => $record->label($this->owner()->default_locale))
                    ->weight('medium'),
                TextColumn::make('description')
                    ->label(__('calls.tracks.columns.description'))
                    ->state(fn (Track $record): ?string => $record->descriptionFor($this->owner()->default_locale))
                    ->limit(80),
                TextColumn::make('position')->label(__('calls.tracks.columns.position')),
                TextColumn::make('archived_at')
                    ->label(__('calls.tracks.columns.status'))
                    ->state(fn (Track $record): string => __($record->isArchived() ? 'calls.tracks.archived' : 'calls.tracks.active'))
                    ->badge()
                    ->color(fn (Track $record): string => $record->isArchived() ? 'gray' : 'success'),
            ])
            ->defaultSort('position')
            ->paginated(false)
            ->headerActions([$this->createAction()])
            ->recordActions([
                $this->moveAction('moveUp', MoveDirection::Up, Heroicon::OutlinedArrowUp),
                $this->moveAction('moveDown', MoveDirection::Down, Heroicon::OutlinedArrowDown),
                $this->editAction(),
                $this->archiveAction(),
                $this->restoreAction(),
            ])
            ->emptyStateHeading(__('calls.tracks.empty_heading'))
            ->emptyStateDescription(__('calls.tracks.empty_description'));
    }

    private function createAction(): Action
    {
        return Action::make('createTrack')
            ->label(__('calls.track_create'))
            ->icon(Heroicon::OutlinedPlus)
            ->schema(fn (): array => TrackFields::inputs($this->owner()))
            ->slideOver(fn (): bool => $this->hasLongForm())
            ->visible(fn (): bool => Gate::allows('create', [Track::class, $this->owner()]))
            ->action(fn (array $data) => $this->run(
                fn () => app(CreateTrack::class)->handle(ActionErrors::actor(), $this->owner(), $data),
                __('calls.tracks.saved'),
            ));
    }

    private function editAction(): Action
    {
        return Action::make('editTrack')
            ->label(__('calls.tracks.edit'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->schema(fn (): array => TrackFields::inputs($this->owner()))
            ->slideOver(fn (): bool => $this->hasLongForm())
            ->fillForm(fn (Track $record): array => [
                'name' => $record->getTranslations('name'),
                'description' => $record->getTranslations('description'),
            ])
            ->visible(fn (Track $record): bool => Gate::allows('update', $record))
            ->action(fn (Track $record, array $data) => $this->run(
                fn () => app(UpdateTrack::class)->handle(ActionErrors::actor(), $record, $data),
                __('calls.tracks.saved'),
            ));
    }

    private function moveAction(string $name, MoveDirection $direction, Heroicon $icon): Action
    {
        return Action::make($name)
            ->label(__("calls.tracks.{$direction->value}"))
            ->icon($icon)
            ->iconButton()
            ->visible(fn (Track $record): bool => Gate::allows('update', $record))
            ->action(fn (Track $record) => $this->run(
                fn () => $this->move($record, $direction),
                __('calls.tracks.moved'),
            ));
    }

    /** Intercambia el eje con su vecino; en los extremos el orden queda igual y no se audita. */
    private function move(Track $track, MoveDirection $direction): void
    {
        $order = Track::query()->where('event_id', $track->event_id)->orderBy('position')->orderBy('id')->pluck('id')->all();
        $index = array_search($track->id, $order, true);
        $target = $direction === MoveDirection::Up ? $index - 1 : $index + 1;

        if ($index !== false && isset($order[$target])) {
            [$order[$index], $order[$target]] = [$order[$target], $order[$index]];
        }

        app(ReorderTracks::class)->handle(ActionErrors::actor(), $this->owner(), $order);
    }

    private function archiveAction(): Action
    {
        return Action::make('archiveTrack')
            ->label(__('calls.tracks.archive'))
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(__('calls.tracks.archive_confirm'))
            ->visible(fn (Track $record): bool => ! $record->isArchived() && Gate::allows('update', $record))
            ->action(fn (Track $record) => $this->run(
                fn () => app(ArchiveTrack::class)->handle(ActionErrors::actor(), $record),
                __('calls.tracks.archived_done'),
            ));
    }

    private function restoreAction(): Action
    {
        return Action::make('restoreTrack')
            ->label(__('calls.tracks.restore'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->visible(fn (Track $record): bool => $record->isArchived() && Gate::allows('update', $record))
            ->action(fn (Track $record) => $this->run(
                fn () => app(RestoreTrack::class)->handle(ActionErrors::actor(), $record),
                __('calls.tracks.restored_done'),
            ));
    }

    /**
     * El formulario tiene nombre y descripción por cada idioma del evento: con tres
     * idiomas son seis campos, más de los cuatro que caben en un modal `md` (plan 015, DA-5).
     */
    private function hasLongForm(): bool
    {
        return count($this->owner()->locales) * 2 > 4;
    }

    private function owner(): Event
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof Event ? $owner : throw new \LogicException('El gestor de ejes temáticos necesita un evento.');
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
