<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\Pages;

use App\Actions\Sponsors\CreateSponsorCategory;
use App\Actions\Sponsors\DeleteSponsorCategory;
use App\Actions\Sponsors\UpdateSponsorCategory;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Support\ActionErrors;
use App\Models\Event;
use App\Models\SponsorCategory;
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
 * Categorías («Niveles») de patrocinador del evento (plan 030, F3): lista, alta, edición y eliminación. Se llega
 * desde el botón «Categorías» de Patrocinadores (no tiene entrada propia en el menú). El evento sale de la ruta,
 * así que uno de otra organización responde 404. Quién entra y quién cambia lo decide `SponsorCategoryPolicy`
 * (`sponsors.manage`; eliminar, `sponsors.delete`). Delgado: las reglas viven en las Actions.
 */
class ManageEventSponsorCategories extends ManageRelatedRecords
{
    protected static string $resource = EventResource::class;

    protected static string $relationship = 'sponsorCategories';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    public static function getNavigationLabel(): string
    {
        return __('sponsors.categories.navigation');
    }

    /** La sección se llama como su ayuda de menú, no «Administrar Categorías de patrocinador Evento». */
    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('sponsors.categories.columns.name'))->weight('medium'),
                TextColumn::make('position')->label(__('sponsors.categories.columns.position')),
            ])
            ->defaultSort('position')
            ->paginationPageOptions([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->recordActions([$this->editAction(), $this->deleteAction()])
            ->emptyStateHeading(__('sponsors.categories.empty_heading'))
            ->emptyStateDescription(__('sponsors.categories.empty_description'));
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [$this->backAction(), $this->createAction()];
    }

    /** Vuelve a la lista de Patrocinadores, de la que se viene. */
    private function backAction(): Action
    {
        return Action::make('back')
            ->label(__('sponsors.categories.back'))
            ->icon(Heroicon::OutlinedArrowLeft)
            ->color('gray')
            ->url(fn (): string => EventResource::getUrl('sponsors', ['record' => $this->event()]));
    }

    private function createAction(): Action
    {
        return Action::make('createCategory')
            ->label(__('sponsors.categories.create'))
            ->icon(Heroicon::OutlinedPlus)
            ->schema(fn (): array => [$this->nameInput()])
            ->visible(fn (): bool => Gate::allows('create', [SponsorCategory::class, $this->event()]))
            ->action(fn (array $data) => $this->run(
                fn () => app(CreateSponsorCategory::class)->handle(ActionErrors::actor(), $this->event(), $data),
                __('sponsors.categories.saved'),
            ));
    }

    private function editAction(): Action
    {
        return Action::make('editCategory')
            ->label(__('sponsors.categories.edit'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->schema(fn (): array => [$this->nameInput()])
            ->fillForm(fn (Model $record): array => ['name' => $this->category($record)->name])
            ->visible(fn (Model $record): bool => Gate::allows('update', $record))
            ->action(fn (Model $record, array $data) => $this->run(
                fn () => app(UpdateSponsorCategory::class)->handle(ActionErrors::actor(), $this->category($record), $data),
                __('sponsors.categories.saved'),
            ));
    }

    private function deleteAction(): Action
    {
        return Action::make('deleteCategory')
            ->label(__('sponsors.categories.delete'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('sponsors.categories.delete_confirm'))
            ->visible(fn (Model $record): bool => Gate::allows('delete', $record))
            ->action(fn (Model $record) => $this->run(
                fn () => app(DeleteSponsorCategory::class)->handle(ActionErrors::actor(), $this->category($record)),
                __('sponsors.categories.deleted'),
            ));
    }

    private function nameInput(): TextInput
    {
        return TextInput::make('name')
            ->label(__('sponsors.categories.name'))
            ->required()
            ->maxLength(100);
    }

    private function category(Model $record): SponsorCategory
    {
        return $record instanceof SponsorCategory ? $record : throw new LogicException('La fila no es una categoría de patrocinador.');
    }

    private function event(): Event
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof Event ? $owner : throw new LogicException('Las categorías de patrocinador necesitan un evento.');
    }

    /** @param  callable(): mixed  $operation */
    private function run(callable $operation, string $successTitle): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            Notification::make()
                ->title(__('sponsors.categories.failed'))
                ->body(ActionErrors::summary($exception))
                ->danger()
                ->send();

            return;
        }

        Notification::make()->title($successTitle)->success()->send();
    }
}
