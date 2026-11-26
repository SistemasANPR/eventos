<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\Pages;

use App\Actions\Program\CreateRoom;
use App\Actions\Program\DeleteRoom;
use App\Actions\Program\MoveRoom;
use App\Actions\Program\UpdateRoom;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Support\ActionErrors;
use App\Models\Event;
use App\Models\Room;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Salas del evento (plan 031, F2): lista, alta, edición, orden y eliminación. Se llega desde el botón
 * «Salas» de Actividades. El evento sale de la ruta, así que un evento de otra organización responde
 * 404. Quién entra y quién cambia lo decide `RoomPolicy` (`activities.manage`; eliminar,
 * `activities.delete`). Delgado: las reglas viven en las Actions; el orden se cambia con «Subir» y
 * «Bajar» (`MoveRoom`), que audita y comprueba el permiso, en vez del arrastre de la tabla.
 */
class ManageEventRooms extends ManageRelatedRecords
{
    protected static string $resource = EventResource::class;

    protected static string $relationship = 'rooms';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    public static function getNavigationLabel(): string
    {
        return __('program.rooms.navigation');
    }

    /** La sección se llama como su entrada de menú, no «Administrar Salas Evento». */
    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('activities'))
            ->columns([
                TextColumn::make('name')->label(__('program.rooms.columns.name'))->weight('medium'),
                TextColumn::make('position')->label(__('program.rooms.columns.position')),
                TextColumn::make('activities_count')->label(__('program.rooms.columns.activities')),
            ])
            ->defaultSort('position')
            ->paginationPageOptions([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->recordActions([$this->moveAction('moveRoomUp', MoveRoom::UP), $this->moveAction('moveRoomDown', MoveRoom::DOWN), $this->editAction(), $this->deleteAction()])
            ->emptyStateHeading(__('program.rooms.empty_heading'))
            ->emptyStateDescription(__('program.rooms.empty_description'));
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [$this->backAction(), $this->createAction()];
    }

    private function backAction(): Action
    {
        return Action::make('back')
            ->label(__('program.rooms.back'))
            ->icon(Heroicon::OutlinedArrowLeft)
            ->color('gray')
            ->url(fn (): string => EventResource::getUrl('activities', ['record' => $this->event()]));
    }

    private function createAction(): Action
    {
        return Action::make('createRoom')
            ->label(__('program.rooms.create'))
            ->icon(Heroicon::OutlinedPlus)
            ->schema(fn (): array => [$this->nameInput()])
            ->visible(fn (): bool => Gate::allows('create', [Room::class, $this->event()]))
            ->action(fn (array $data) => $this->run(
                fn () => app(CreateRoom::class)->handle(ActionErrors::actor(), $this->event(), $data),
                __('program.rooms.saved'),
            ));
    }

    private function editAction(): Action
    {
        return Action::make('editRoom')
            ->label(__('program.rooms.edit'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->schema(fn (): array => [$this->nameInput()])
            ->fillForm(fn (Model $record): array => ['name' => $this->room($record)->name])
            ->visible(fn (Model $record): bool => Gate::allows('update', $record))
            ->action(fn (Model $record, array $data) => $this->run(
                fn () => app(UpdateRoom::class)->handle(ActionErrors::actor(), $this->room($record), $data),
                __('program.rooms.saved'),
            ));
    }

    /** «Subir» o «Bajar»: cambia el orden de las columnas de la agenda un lugar. */
    private function moveAction(string $name, string $direction): Action
    {
        $up = $direction === MoveRoom::UP;

        return Action::make($name)
            ->label(__($up ? 'program.rooms.move_up' : 'program.rooms.move_down'))
            ->icon($up ? Heroicon::OutlinedArrowUp : Heroicon::OutlinedArrowDown)
            ->iconButton()
            ->visible(fn (Model $record): bool => Gate::allows('update', $record))
            ->action(fn (Model $record) => $this->run(
                fn () => app(MoveRoom::class)->handle(ActionErrors::actor(), $this->room($record), $direction),
                __('program.rooms.moved'),
            ));
    }

    private function deleteAction(): Action
    {
        return Action::make('deleteRoom')
            ->label(__('program.rooms.delete'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('program.rooms.delete_confirm'))
            ->visible(fn (Model $record): bool => Gate::allows('delete', $record))
            ->action(fn (Model $record) => $this->run(
                fn () => app(DeleteRoom::class)->handle(ActionErrors::actor(), $this->room($record)),
                __('program.rooms.deleted'),
            ));
    }

    private function nameInput(): TextInput
    {
        return TextInput::make('name')
            ->label(__('program.rooms.name'))
            ->required()
            ->maxLength(100);
    }

    private function room(Model $record): Room
    {
        return $record instanceof Room ? $record : throw new LogicException('La fila no es una sala.');
    }

    private function event(): Event
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof Event ? $owner : throw new LogicException('Las salas necesitan un evento.');
    }

    /** @param  callable(): mixed  $operation */
    private function run(callable $operation, string $successTitle): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            Notification::make()
                ->title(__('program.rooms.failed'))
                ->body(ActionErrors::summary($exception))
                ->danger()
                ->send();

            return;
        }

        Notification::make()->title($successTitle)->success()->send();
    }
}
