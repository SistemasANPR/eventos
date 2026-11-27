<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\Pages;

use App\Actions\Sponsors\CreateSponsor;
use App\Actions\Sponsors\DeleteSponsor;
use App\Actions\Sponsors\UpdateSponsor;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Support\ActionErrors;
use App\Filament\Support\ExtraFieldDefinitionsAction;
use App\Filament\Support\ExtraFieldsInput;
use App\Models\Event;
use App\Models\ExtraFieldDefinition;
use App\Models\Sponsor;
use App\Models\SponsorCategory;
use App\Support\Files\MediaThumbnail;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Patrocinadores del evento (plan 030, F2): lista, alta, edición y eliminación. El evento sale de la ruta, así
 * que uno de otra organización responde 404. Quién entra y quién cambia lo decide `SponsorPolicy`
 * (`sponsors.manage`; eliminar, `sponsors.delete`). Delgado: las reglas viven en las Actions.
 */
class ManageEventSponsors extends ManageRelatedRecords
{
    protected static string $resource = EventResource::class;

    protected static string $relationship = 'sponsors';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    public static function getNavigationLabel(): string
    {
        return __('sponsors.navigation');
    }

    /** La sección se llama como su entrada de menú, no «Administrar Patrocinadores Evento». */
    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('category'))
            ->columns([
                TextColumn::make('position')->label(__('sponsors.columns.position'))->sortable(),
                ImageColumn::make('logo_path')
                    ->label(__('sponsors.columns.logo'))
                    ->state(fn (Sponsor $record): ?string => MediaThumbnail::dataUri($record->logo_path))
                    ->imageSize(40),
                TextColumn::make('name')->label(__('sponsors.columns.name'))->searchable()->weight('medium'),
                TextColumn::make('category.name')->label(__('sponsors.columns.level'))->badge()->placeholder('—')->toggleable(),
                TextColumn::make('website_url')->label(__('sponsors.columns.website_url'))->url(fn (Sponsor $record): ?string => $record->website_url, shouldOpenInNewTab: true)->limit(40)->toggleable(),
            ])
            ->filters([
                SelectFilter::make('level')
                    ->label(__('sponsors.columns.level'))
                    ->options(fn (): array => $this->categoryOptions())
                    ->query(fn (Builder $query, array $data): Builder => is_string($data['value'] ?? null) && $data['value'] !== ''
                        ? $query->where('sponsor_category_id', $data['value'])
                        : $query),
            ])
            ->defaultSort('position')
            ->paginationPageOptions([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->recordActions([$this->editAction(), $this->deleteAction()])
            ->emptyStateHeading(__('sponsors.empty_heading'))
            ->emptyStateDescription(__('sponsors.empty_description'));
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [$this->categoriesAction(), ExtraFieldDefinitionsAction::make(ExtraFieldDefinition::SCOPE_SPONSOR, fn (): Event => $this->event()), $this->createAction()];
    }

    /** Botón «Categorías» (con su conteo) que lleva al catálogo de niveles del evento. */
    private function categoriesAction(): Action
    {
        return Action::make('categories')
            ->label(__('sponsors.categories.button'))
            ->icon(Heroicon::OutlinedTag)
            ->color('gray')
            ->outlined()
            ->badge(fn (): int => SponsorCategory::query()->where('event_id', $this->event()->id)->count())
            ->url(fn (): string => EventResource::getUrl('sponsor-categories', ['record' => $this->event()]))
            ->visible(fn (): bool => Gate::allows('viewAny', SponsorCategory::class));
    }

    /**
     * Niveles del evento para el selector y el filtro, en su orden.
     *
     * @return array<string, string>
     */
    private function categoryOptions(): array
    {
        return SponsorCategory::query()->where('event_id', $this->event()->id)->orderBy('position')->pluck('name', 'id')->all();
    }

    /** @return array<int, mixed> */
    private function components(): array
    {
        return [
            TextInput::make('name')->label(__('sponsors.fields.name'))->required()->maxLength(160),
            Select::make('sponsor_category_id')
                ->label(__('sponsors.fields.level'))
                ->helperText(__('sponsors.fields.level_help'))
                ->options(fn (): array => $this->categoryOptions())
                ->placeholder(__('sponsors.fields.level_none')),
            TextInput::make('website_url')->label(__('sponsors.fields.website_url'))->helperText(__('sponsors.fields.website_url_help'))->url()->maxLength(2048),
            // No se guarda en el disco del formulario: la sube `StoreProgramImage`, que decide por el contenido.
            FileUpload::make('logo')
                ->label(__('sponsors.fields.logo'))
                ->helperText(__('sponsors.fields.logo_help'))
                ->image()
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->maxSize(2 * 1024)
                ->storeFiles(false),
            Toggle::make('remove_logo')
                ->label(__('sponsors.fields.remove_logo'))
                ->visible(fn (?Model $record): bool => $record instanceof Sponsor && $record->logo_path !== null),
            TextInput::make('position')->label(__('sponsors.fields.position'))->helperText(__('sponsors.fields.position_help'))->numeric()->integer()->minValue(0)->maxValue(9999),
            ...ExtraFieldsInput::components($this->event(), ExtraFieldDefinition::SCOPE_SPONSOR),
        ];
    }

    private function createAction(): Action
    {
        return Action::make('createSponsor')
            ->label(__('sponsors.create'))
            ->icon(Heroicon::OutlinedPlus)
            ->modalWidth('2xl')
            ->schema(fn (): array => $this->components())
            ->visible(fn (): bool => Gate::allows('create', [Sponsor::class, $this->event()]))
            ->action(function (array $data, Schema $schema): void {
                $this->run(
                    fn () => app(CreateSponsor::class)->handle(ActionErrors::actor(), $this->event(), self::input($data)),
                    __('sponsors.saved'),
                    $schema,
                );
            });
    }

    private function editAction(): Action
    {
        return Action::make('editSponsor')
            ->label(__('sponsors.edit'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->modalWidth('2xl')
            ->schema(fn (): array => $this->components())
            ->fillForm(fn (Model $record): array => [
                ...$this->sponsor($record)->only(['name', 'sponsor_category_id', 'website_url', 'position']),
                ...ExtraFieldsInput::fill($this->sponsor($record)),
            ])
            ->visible(fn (Model $record): bool => Gate::allows('update', $record))
            ->action(function (Model $record, array $data, Schema $schema): void {
                $this->run(
                    fn () => app(UpdateSponsor::class)->handle(ActionErrors::actor(), $this->sponsor($record), self::input($data)),
                    __('sponsors.saved'),
                    $schema,
                );
            });
    }

    private function deleteAction(): Action
    {
        return Action::make('deleteSponsor')
            ->label(__('sponsors.delete'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('sponsors.delete_confirm'))
            ->visible(fn (Model $record): bool => Gate::allows('delete', $record))
            ->action(fn (Model $record) => $this->run(
                fn () => app(DeleteSponsor::class)->handle(ActionErrors::actor(), $this->sponsor($record)),
                __('sponsors.deleted'),
            ));
    }

    /**
     * El formulario trae el logotipo subido (sin guardar) como archivo temporal: se pasa tal cual a la Action,
     * que lo guarda por su contenido. Sin archivo nuevo no se envía la clave y el logotipo no cambia.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function input(array $data): array
    {
        $logo = $data['logo'] ?? null;
        unset($data['logo']);

        foreach (is_array($logo) ? $logo : [$logo] as $file) {
            if ($file instanceof UploadedFile) {
                $data['logo'] = $file;
                break;
            }
        }

        if (($data['remove_logo'] ?? false) !== true) {
            unset($data['remove_logo']);
        }

        return ExtraFieldsInput::input($data);
    }

    private function sponsor(Model $record): Sponsor
    {
        return $record instanceof Sponsor ? $record : throw new LogicException('La fila no es un patrocinador.');
    }

    private function event(): Event
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof Event ? $owner : throw new LogicException('Los patrocinadores necesitan un evento.');
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
                Notification::make()->title(__('sponsors.failed'))->body(ActionErrors::summary($exception))->danger()->send();

                return;
            }

            throw ActionErrors::forForm($exception, $schema->getStatePath());
        }

        Notification::make()->title($successTitle)->success()->send();
    }
}
