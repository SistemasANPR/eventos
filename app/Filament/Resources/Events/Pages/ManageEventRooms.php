<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\Pages;

use App\Actions\Program\CreateRoom;
use App\Actions\Program\DeleteRoom;
use App\Actions\Program\MoveRoom;
use App\Actions\Program\UpdateRoom;
use App\Enums\RoomKind;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Support\ActionErrors;
use App\Models\Event;
use App\Models\Room;
use App\Support\Program\RoomWrites;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Lugares del evento (plan 031, F2; plan 035, F2): lista, alta, edición, orden y eliminación de salas y de lugares
 * externos, con su capacidad, dirección y mapa. Se llega desde el botón «Lugares» de Actividades. El evento sale de la ruta, así que un evento de otra organización responde
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
                TextColumn::make('kind')
                    ->label(__('program.rooms.columns.kind'))
                    ->badge()
                    ->state(fn (Room $record): string => __('program.rooms.kinds.'.$record->kind->value))
                    ->color(fn (Room $record): string => $record->kind === RoomKind::External ? 'info' : 'gray'),
                TextColumn::make('capacity')->label(__('program.rooms.columns.capacity'))->placeholder('—'),
                TextColumn::make('address')->label(__('program.rooms.columns.address'))->placeholder('—')->limit(40)->toggleable(isToggledHiddenByDefault: true),
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
            ->schema(fn (): array => $this->fields())
            ->fillForm(fn (): array => ['kind' => RoomKind::Room->value])
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
            ->schema(fn (): array => $this->fields())
            ->fillForm(fn (Model $record): array => $this->formState($this->room($record)))
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

    /**
     * Campos del alta y la edición: nombre y tipo siempre; capacidad, dirección y mapa son opcionales y traen
     * texto de ayuda que orienta a quien los llena (plan 035, DA-8). La capacidad pública solo se ofrece si hay capacidad.
     *
     * @return list<mixed>
     */
    private function fields(): array
    {
        return [
            TextInput::make('name')
                ->label(__('program.rooms.name'))
                ->required()
                ->maxLength(100),
            Select::make('kind')
                ->label(__('program.rooms.kind'))
                ->helperText(__('program.rooms.kind_help'))
                ->options(collect(RoomKind::cases())->mapWithKeys(fn (RoomKind $kind): array => [$kind->value => __('program.rooms.kinds.'.$kind->value)])->all())
                ->default(RoomKind::Room->value)
                ->selectablePlaceholder(false)
                ->required(),
            TextInput::make('capacity')
                ->label(__('program.rooms.capacity'))
                ->helperText(__('program.rooms.capacity_help'))
                ->numeric()
                ->integer()
                ->minValue(1)
                ->maxValue(RoomWrites::MAX_CAPACITY)
                ->live(onBlur: true),
            Toggle::make('capacity_is_public')
                ->label(__('program.rooms.capacity_is_public'))
                ->helperText(__('program.rooms.capacity_is_public_help'))
                ->visible(fn (Get $get): bool => filled($get('capacity'))),
            TextInput::make('address')
                ->label(__('program.rooms.address'))
                ->helperText(__('program.rooms.address_help'))
                ->maxLength(255),
            TextInput::make('map_url')
                ->label(__('program.rooms.map_url'))
                ->helperText(__('program.rooms.map_help'))
                ->url()
                ->maxLength(2048),
        ];
    }

    /** @return array<string, mixed> */
    private function formState(Room $room): array
    {
        return [
            'name' => $room->name,
            'kind' => $room->kind->value,
            'capacity' => $room->capacity,
            'capacity_is_public' => $room->capacity_is_public,
            'address' => $room->address,
            'map_url' => $room->map_url,
        ];
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
