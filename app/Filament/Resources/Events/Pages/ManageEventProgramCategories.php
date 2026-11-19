<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\Pages;

use App\Actions\Program\CreateProgramCategory;
use App\Actions\Program\DeleteProgramCategory;
use App\Actions\Program\UpdateProgramCategory;
use App\Enums\ProgramCategoryKind;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Support\ActionErrors;
use App\Models\ActivityCategory;
use App\Models\Event;
use App\Models\SpeakerCategory;
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
 * Catálogo de categorías del programa del evento (plan 021, F1): lista, alta, edición y eliminación.
 * Base de «Categorías de ponentes» y «Categorías de actividades», que solo difieren en el catálogo
 * (`kind()`). El evento sale de la ruta, así que un evento de otra organización responde 404. Quién
 * entra y quién cambia lo deciden `SpeakerCategoryPolicy` y `ActivityCategoryPolicy` (`speakers.manage`
 * o `activities.manage`; eliminar, `.delete`). Delgado: las reglas viven en las Actions.
 */
abstract class ManageEventProgramCategories extends ManageRelatedRecords
{
    protected static string $resource = EventResource::class;

    abstract protected static function kind(): ProgramCategoryKind;

    public static function getNavigationLabel(): string
    {
        return __('program.categories.'.static::kind()->value.'.navigation');
    }

    /** La sección se llama como su entrada de menú, no «Administrar Categorías Evento». */
    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function table(Table $table): Table
    {
        $kind = static::kind();

        return $table
            ->columns([
                TextColumn::make('name')->label(__('program.categories.columns.name'))->weight('medium'),
                TextColumn::make('position')->label(__('program.categories.columns.position')),
            ])
            ->defaultSort('position')
            ->paginationPageOptions([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->recordActions([$this->editAction(), $this->deleteAction()])
            ->emptyStateHeading(__("program.categories.{$kind->value}.empty_heading"))
            ->emptyStateDescription(__("program.categories.{$kind->value}.empty_description"));
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [$this->backAction(), $this->createAction()];
    }

    /** Vuelve a la lista de la que se viene: Ponentes o Actividades. */
    private function backAction(): Action
    {
        $kind = static::kind();

        return Action::make('back')
            ->label(__("program.categories.back_to_{$kind->value}"))
            ->icon(Heroicon::OutlinedArrowLeft)
            ->color('gray')
            ->url(fn (): string => EventResource::getUrl($kind->value, ['record' => $this->event()]));
    }

    private function createAction(): Action
    {
        $kind = static::kind();

        return Action::make('createCategory')
            ->label(__('program.categories.create'))
            ->icon(Heroicon::OutlinedPlus)
            ->schema(fn (): array => [$this->nameInput()])
            ->visible(fn (): bool => Gate::allows('create', [$kind->modelClass(), $this->event()]))
            ->action(fn (array $data) => $this->run(
                fn () => app(CreateProgramCategory::class)->handle(ActionErrors::actor(), $this->event(), $kind, $data),
                __('program.categories.saved'),
            ));
    }

    private function editAction(): Action
    {
        return Action::make('editCategory')
            ->label(__('program.categories.edit'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->schema(fn (): array => [$this->nameInput()])
            ->fillForm(fn (Model $record): array => ['name' => $this->category($record)->name])
            ->visible(fn (Model $record): bool => Gate::allows('update', $record))
            ->action(fn (Model $record, array $data) => $this->run(
                fn () => app(UpdateProgramCategory::class)->handle(ActionErrors::actor(), $this->category($record), $data),
                __('program.categories.saved'),
            ));
    }

    private function deleteAction(): Action
    {
        return Action::make('deleteCategory')
            ->label(__('program.categories.delete'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('program.categories.'.static::kind()->value.'.delete_confirm'))
            ->visible(fn (Model $record): bool => Gate::allows('delete', $record))
            ->action(fn (Model $record) => $this->run(
                fn () => app(DeleteProgramCategory::class)->handle(ActionErrors::actor(), $this->category($record)),
                __('program.categories.deleted'),
            ));
    }

    private function nameInput(): TextInput
    {
        return TextInput::make('name')
            ->label(__('program.categories.name'))
            ->required()
            ->maxLength(100);
    }

    private function category(Model $record): SpeakerCategory|ActivityCategory
    {
        return $record instanceof SpeakerCategory || $record instanceof ActivityCategory
            ? $record
            : throw new LogicException('La fila no es una categoría del programa.');
    }

    private function event(): Event
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof Event ? $owner : throw new LogicException('Las categorías del programa necesitan un evento.');
    }

    /** @param  callable(): mixed  $operation */
    private function run(callable $operation, string $successTitle): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            Notification::make()
                ->title(__('program.categories.failed'))
                ->body(ActionErrors::summary($exception))
                ->danger()
                ->send();

            return;
        }

        Notification::make()->title($successTitle)->success()->send();
    }
}
