<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\Pages;

use App\Actions\Program\CreateSpeaker;
use App\Actions\Program\DeleteSpeaker;
use App\Actions\Program\SetSpeakerPublication;
use App\Actions\Program\UpdateSpeaker;
use App\Enums\ProgramCategoryKind;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\ProgramCategoryShortcuts;
use App\Filament\Resources\Events\Schemas\SpeakerForm;
use App\Filament\Support\ActionErrors;
use App\Models\Event;
use App\Models\Speaker;
use App\Support\Files\MediaThumbnail;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Ponentes del evento (plan 021, F2): lista, alta, edición, publicación y eliminación. El evento sale
 * de la ruta, así que un evento de otra organización responde 404. Quién entra y quién cambia lo
 * decide `SpeakerPolicy` (`speakers.manage`; eliminar, `speakers.delete`); ver exige el mismo permiso
 * que gestionar. Delgado: las reglas viven en las Actions y en `StoreSpeakerRequest`.
 */
class ManageEventSpeakers extends ManageRelatedRecords
{
    protected static string $resource = EventResource::class;

    protected static string $relationship = 'speakers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMicrophone;

    public static function getNavigationLabel(): string
    {
        return __('program.speakers.navigation');
    }

    /** La sección se llama como su entrada de menú, no «Administrar Ponentes Evento». */
    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('categories'))
            ->columns([
                ImageColumn::make('photo_path')
                    ->label(__('program.speakers.columns.photo'))
                    ->state(fn (Speaker $record): ?string => MediaThumbnail::dataUri($record->photo_path))
                    ->circular()
                    ->imageSize(40),
                TextColumn::make('first_name')->label(__('program.speakers.columns.first_name'))->searchable()->sortable()->weight('medium'),
                TextColumn::make('last_name')->label(__('program.speakers.columns.last_name'))->searchable()->sortable()->weight('medium'),
                TextColumn::make('company')->label(__('program.speakers.columns.company'))->searchable()->toggleable(),
                TextColumn::make('job_title')->label(__('program.speakers.columns.job_title'))->searchable()->toggleable(),
                TextColumn::make('categories')
                    ->label(__('program.speakers.columns.categories'))
                    ->state(fn (Speaker $record): array => $record->categories->pluck('name')->all())
                    ->badge(),
                TextColumn::make('is_published')
                    ->label(__('program.speakers.columns.is_published'))
                    ->state(fn (Speaker $record): string => self::publicationLabel($record))
                    ->badge()
                    ->color(fn (Speaker $record): string => match (true) {
                        $record->is_published => 'success',
                        $record->hidden_by_reversal_at !== null => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label(__('program.speakers.filters.category'))
                    ->options(fn (): array => SpeakerForm::categoryOptions($this->event()))
                    ->query(fn (Builder $query, array $data): Builder => is_string($data['value'] ?? null) && $data['value'] !== ''
                        ? $query->whereHas('categories', fn (Builder $categories): Builder => $categories->whereKey($data['value']))
                        : $query),
                TernaryFilter::make('is_published')
                    ->label(__('program.speakers.filters.published'))
                    ->trueLabel(__('program.speakers.filters.published_yes'))
                    ->falseLabel(__('program.speakers.filters.published_no')),
            ])
            ->defaultSort('last_name')
            ->paginationPageOptions([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->recordActions([$this->editAction(), $this->publicationAction(), $this->deleteAction()])
            ->emptyStateHeading(__('program.speakers.empty_heading'))
            ->emptyStateDescription(__('program.speakers.empty_description'));
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [ProgramCategoryShortcuts::button($this->event(), ProgramCategoryKind::Speaker), $this->createAction()];
    }

    private function createAction(): Action
    {
        return Action::make('createSpeaker')
            ->label(__('program.speakers.create'))
            ->icon(Heroicon::OutlinedPlus)
            ->modalWidth('3xl')
            ->schema(fn (): array => SpeakerForm::components($this->event()))
            ->visible(fn (): bool => Gate::allows('create', [Speaker::class, $this->event()]))
            ->action(function (array $data, Schema $schema): void {
                $this->run(
                    fn () => app(CreateSpeaker::class)->handle(ActionErrors::actor(), $this->event(), self::input($data)),
                    __('program.speakers.saved'),
                    $schema,
                );
            });
    }

    private function editAction(): Action
    {
        return Action::make('editSpeaker')
            ->label(__('program.speakers.edit'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->modalWidth('3xl')
            ->schema(fn (): array => SpeakerForm::components($this->event()))
            ->fillForm(fn (Model $record): array => $this->formState($this->speaker($record)))
            ->visible(fn (Model $record): bool => Gate::allows('update', $record))
            ->action(function (Model $record, array $data, Schema $schema): void {
                $this->run(
                    fn () => app(UpdateSpeaker::class)->handle(ActionErrors::actor(), $this->speaker($record), self::input($data)),
                    __('program.speakers.saved'),
                    $schema,
                );
            });
    }

    private function publicationAction(): Action
    {
        return Action::make('togglePublication')
            ->label(fn (Model $record): string => $this->speaker($record)->is_published ? __('program.speakers.unpublish') : __('program.speakers.publish'))
            ->icon(fn (Model $record): Heroicon => $this->speaker($record)->is_published ? Heroicon::OutlinedEyeSlash : Heroicon::OutlinedEye)
            ->visible(fn (Model $record): bool => Gate::allows('update', $record))
            ->action(function (Model $record): void {
                $publish = ! $this->speaker($record)->is_published;

                $this->run(
                    fn () => app(SetSpeakerPublication::class)->handle(ActionErrors::actor(), $this->speaker($record), $publish),
                    __($publish ? 'program.speakers.published' : 'program.speakers.unpublished'),
                );
            });
    }

    private function deleteAction(): Action
    {
        return Action::make('deleteSpeaker')
            ->label(__('program.speakers.delete'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('program.speakers.delete_confirm'))
            ->visible(fn (Model $record): bool => Gate::allows('delete', $record))
            ->action(fn (Model $record) => $this->run(
                fn () => app(DeleteSpeaker::class)->handle(ActionErrors::actor(), $this->speaker($record)),
                __('program.speakers.deleted'),
            ));
    }

    /**
     * El formulario trae la fotografía subida (sin guardar) como archivo temporal: se pasa tal cual a la
     * Action, que la guarda por su contenido. Sin archivo nuevo no se envía la clave y la foto no cambia.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function input(array $data): array
    {
        $photo = $data['photo'] ?? null;
        unset($data['photo']);

        foreach (is_array($photo) ? $photo : [$photo] as $file) {
            if ($file instanceof UploadedFile) {
                $data['photo'] = $file;
                break;
            }
        }

        if (($data['remove_photo'] ?? false) !== true) {
            unset($data['remove_photo']);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function formState(Speaker $speaker): array
    {
        return [
            'first_name' => $speaker->first_name,
            'last_name' => $speaker->last_name,
            'company' => $speaker->company,
            'job_title' => $speaker->job_title,
            'email' => $speaker->email,
            'country' => $speaker->country,
            'tagline' => $speaker->tagline,
            'bio' => $speaker->bio,
            'social_links' => $speaker->social_links,
            'category_ids' => $speaker->categories()->pluck('speaker_categories.id')->all(),
            'activity_ids' => $speaker->activities()->pluck('event_activities.id')->all(),
            'is_published' => $speaker->is_published,
        ];
    }

    private static function publicationLabel(Speaker $speaker): string
    {
        return match (true) {
            $speaker->is_published => __('program.speakers.filters.published_yes'),
            $speaker->hidden_by_reversal_at !== null => __('program.speakers.hidden_by_reversal'),
            default => __('program.speakers.filters.published_no'),
        };
    }

    private function speaker(Model $record): Speaker
    {
        return $record instanceof Speaker ? $record : throw new LogicException('La fila no es un ponente.');
    }

    private function event(): Event
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof Event ? $owner : throw new LogicException('Los ponentes necesitan un evento.');
    }

    /**
     * Ejecuta la Action y lleva sus errores de validación a los campos del formulario; las redes
     * (un error por elemento del repetidor) se muestran junto al repetidor. Una falta de autorización
     * y cualquier otro fallo se propagan: no se disfrazan de éxito.
     *
     * @param  callable(): mixed  $operation
     */
    private function run(callable $operation, string $successTitle, ?Schema $schema = null): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            if ($schema === null) {
                Notification::make()->title(__('program.speakers.failed'))->body(ActionErrors::summary($exception))->danger()->send();

                return;
            }

            $errors = [];

            foreach ($exception->errors() as $field => $messages) {
                $key = match (true) {
                    str_starts_with($field, 'social_links.') => 'social_links',
                    str_starts_with($field, 'category_ids.') => 'category_ids',
                    str_starts_with($field, 'activity_ids.') => 'activity_ids',
                    default => $field,
                };
                $errors[$key] = [...($errors[$key] ?? []), ...$messages];
            }

            throw ActionErrors::forForm(ValidationException::withMessages($errors), $schema->getStatePath());
        }

        Notification::make()->title($successTitle)->success()->send();
    }
}
