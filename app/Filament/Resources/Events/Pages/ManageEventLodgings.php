<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\Pages;

use App\Actions\Lodgings\CreateLodging;
use App\Actions\Lodgings\DeleteLodging;
use App\Actions\Lodgings\UpdateLodging;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Support\ActionErrors;
use App\Filament\Support\ExtraFieldsInput;
use App\Models\Event;
use App\Models\Lodging;
use App\Models\LodgingImage;
use App\Support\Files\MediaThumbnail;
use App\Support\Lodgings\LodgingRules;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Hospedaje del evento (plan 030, F2): lista, alta, edición y eliminación. El evento sale de la ruta, así que
 * uno de otra organización responde 404. Quién entra y quién cambia lo decide `LodgingPolicy`
 * (`lodgings.manage`; eliminar, `lodgings.delete`). Delgado: las reglas viven en las Actions.
 */
class ManageEventLodgings extends ManageRelatedRecords
{
    protected static string $resource = EventResource::class;

    protected static string $relationship = 'lodgings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    public static function getNavigationLabel(): string
    {
        return __('lodgings.navigation');
    }

    /** La sección se llama como su entrada de menú, no «Administrar Hospedajes Evento». */
    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('images')->withCount('images'))
            ->columns([
                TextColumn::make('position')->label(__('lodgings.columns.position'))->sortable(),
                // Miniatura de la primera imagen y cuántas tiene: el disco es privado, la miniatura va incrustada.
                ImageColumn::make('cover')
                    ->label(__('lodgings.columns.images'))
                    ->state(fn (Lodging $record): ?string => MediaThumbnail::dataUri($record->images->first()?->path))
                    ->imageSize(40),
                TextColumn::make('images_count')->label(__('lodgings.columns.images_count'))->badge()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('name')->label(__('lodgings.columns.name'))->searchable()->weight('medium'),
                TextColumn::make('address')->label(__('lodgings.columns.address'))->toggleable(),
                TextColumn::make('rate')->label(__('lodgings.columns.rate'))->toggleable(),
                TextColumn::make('booking_url')->label(__('lodgings.columns.booking_url'))->url(fn (Lodging $record): ?string => $record->booking_url, shouldOpenInNewTab: true)->limit(40)->toggleable(),
            ])
            ->defaultSort('position')
            ->paginationPageOptions([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->recordActions([$this->editAction(), $this->deleteAction()])
            ->emptyStateHeading(__('lodgings.empty_heading'))
            ->emptyStateDescription(__('lodgings.empty_description'));
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [$this->createAction()];
    }

    /** @return array<int, mixed> */
    private static function components(): array
    {
        return [
            TextInput::make('name')->label(__('lodgings.fields.name'))->required()->maxLength(160),
            TextInput::make('address')->label(__('lodgings.fields.address'))->maxLength(255),
            Textarea::make('description')->label(__('lodgings.fields.description'))->rows(3)->maxLength(2000),
            TextInput::make('booking_url')->label(__('lodgings.fields.booking_url'))->helperText(__('lodgings.fields.booking_url_help'))->url()->maxLength(2048),
            TextInput::make('rate')->label(__('lodgings.fields.rate'))->maxLength(160),
            // Las imágenes actuales se muestran con su miniatura y se marcan para quitarlas una a una.
            CheckboxList::make('remove_images')
                ->label(__('lodgings.fields.remove_images'))
                ->options(fn (?Model $record): array => $record instanceof Lodging ? self::currentImages($record) : [])
                ->allowHtml()
                ->columns(5)
                ->visible(fn (?Model $record): bool => $record instanceof Lodging && $record->images()->exists()),
            // No se guardan en el disco del formulario: las sube `StoreProgramImage`, que decide por el contenido.
            FileUpload::make('images')
                ->label(__('lodgings.fields.images'))
                ->helperText(__('lodgings.fields.images_help', ['max' => LodgingRules::MAX_IMAGES]))
                ->image()
                ->multiple()
                ->maxFiles(LodgingRules::MAX_IMAGES)
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->maxSize(2 * 1024)
                ->storeFiles(false),
            TextInput::make('position')->label(__('lodgings.fields.position'))->helperText(__('lodgings.fields.position_help'))->numeric()->integer()->minValue(0)->maxValue(9999),
            ExtraFieldsInput::make(),
        ];
    }

    /**
     * Imágenes del hospedaje como opciones de casilla: su miniatura incrustada (`data:`, hecha en el servidor).
     *
     * @return array<string, string>
     */
    private static function currentImages(Lodging $lodging): array
    {
        return $lodging->images->mapWithKeys(fn (LodgingImage $image): array => [
            $image->id => (new HtmlString(
                '<img src="'.e((string) MediaThumbnail::dataUri($image->path, 160)).'" alt="" style="height:4rem;width:auto;border-radius:0.375rem">',
            ))->toHtml(),
        ])->all();
    }

    /**
     * El formulario trae las imágenes subidas (sin guardar) como archivos temporales: se pasan tal cual a la
     * Action, que las guarda por su contenido. Sin archivos nuevos no se envía la clave y la galería no cambia.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function input(array $data): array
    {
        $images = $data['images'] ?? [];
        unset($data['images']);

        $files = array_values(array_filter(is_array($images) ? $images : [$images], fn (mixed $file): bool => $file instanceof UploadedFile));

        if ($files !== []) {
            $data['images'] = $files;
        }

        if (! is_array($data['remove_images'] ?? null) || $data['remove_images'] === []) {
            unset($data['remove_images']);
        }

        return ExtraFieldsInput::input($data);
    }

    private function createAction(): Action
    {
        return Action::make('createLodging')
            ->label(__('lodgings.create'))
            ->icon(Heroicon::OutlinedPlus)
            ->modalWidth('2xl')
            ->schema(self::components())
            ->visible(fn (): bool => Gate::allows('create', [Lodging::class, $this->event()]))
            ->action(function (array $data, Schema $schema): void {
                $this->run(
                    fn () => app(CreateLodging::class)->handle(ActionErrors::actor(), $this->event(), self::input($data)),
                    __('lodgings.saved'),
                    $schema,
                );
            });
    }

    private function editAction(): Action
    {
        return Action::make('editLodging')
            ->label(__('lodgings.edit'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->modalWidth('2xl')
            ->schema(self::components())
            ->fillForm(fn (Model $record): array => [
                ...$this->lodging($record)->only(['name', 'address', 'description', 'booking_url', 'rate', 'position']),
                'extra_fields' => ExtraFieldsInput::fill($this->lodging($record)->extra_fields),
            ])
            ->visible(fn (Model $record): bool => Gate::allows('update', $record))
            ->action(function (Model $record, array $data, Schema $schema): void {
                $this->run(
                    fn () => app(UpdateLodging::class)->handle(ActionErrors::actor(), $this->lodging($record), self::input($data)),
                    __('lodgings.saved'),
                    $schema,
                );
            });
    }

    private function deleteAction(): Action
    {
        return Action::make('deleteLodging')
            ->label(__('lodgings.delete'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('lodgings.delete_confirm'))
            ->visible(fn (Model $record): bool => Gate::allows('delete', $record))
            ->action(fn (Model $record) => $this->run(
                fn () => app(DeleteLodging::class)->handle(ActionErrors::actor(), $this->lodging($record)),
                __('lodgings.deleted'),
            ));
    }

    private function lodging(Model $record): Lodging
    {
        return $record instanceof Lodging ? $record : throw new LogicException('La fila no es un hospedaje.');
    }

    private function event(): Event
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof Event ? $owner : throw new LogicException('El hospedaje necesita un evento.');
    }

    /**
     * Ejecuta la Action y lleva sus errores de validación a los campos del formulario. Una falta de
     * autorización y cualquier otro fallo se propagan: no se disfrazan de éxito.
     *
     * @param  callable(): mixed  $operation
     */
    private function run(callable $operation, string $successTitle, ?Schema $schema = null): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            if ($schema === null) {
                Notification::make()->title(__('lodgings.failed'))->body(ActionErrors::summary($exception))->danger()->send();

                return;
            }

            throw ActionErrors::forForm($exception, $schema->getStatePath());
        }

        Notification::make()->title($successTitle)->success()->send();
    }
}
