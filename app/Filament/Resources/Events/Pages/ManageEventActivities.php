<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\Pages;

use App\Actions\Program\CreateActivity;
use App\Actions\Program\DeleteActivity;
use App\Actions\Program\SetActivityPublication;
use App\Actions\Program\UpdateActivity;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Support\ActionErrors;
use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Speaker;
use App\Support\Files\MediaDisk;
use App\Support\Files\MediaThumbnail;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Image;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use LogicException;

/**
 * Actividades del programa del evento (plan 021, F3): lista, alta, edición, publicación y
 * eliminación. El evento sale de la ruta, así que un evento de otra organización responde 404. Quién
 * entra y quién cambia lo deciden `EventActivityPolicy` (`activities.manage`; eliminar,
 * `activities.delete`). Delgado: las reglas viven en las Actions. Fecha y hora son las LOCALES del
 * evento (DA-12); una actividad sin ellas muestra «Sin programar». Las imágenes se previsualizan con
 * una miniatura incrustada hecha en el servidor (`MediaThumbnail`, igual que la foto del ponente): el
 * disco es privado y la ruta pública solo sirve lo publicado, así que el panel no depende de ella.
 */
class ManageEventActivities extends ManageRelatedRecords
{
    protected static string $resource = EventResource::class;

    protected static string $relationship = 'activities';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    public static function getNavigationLabel(): string
    {
        return __('program.activities.navigation');
    }

    /** La sección se llama como su entrada de menú, no «Administrar Actividades Evento». */
    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function table(Table $table): Table
    {
        $event = $this->event();

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['submission', 'categories', 'speakers']))
            ->columns([
                ImageColumn::make('thumbnail')
                    ->label(__('program.activities.columns.image'))
                    ->state(fn (EventActivity $record): ?string => self::mediaUrl($record, $record->image_path !== null ? 'image' : 'icon'))
                    ->square()
                    ->imageSize(40),
                TextColumn::make('title')
                    ->label(__('program.activities.columns.title'))
                    ->weight('medium')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('starts_on')
                    ->label(__('program.activities.columns.date'))
                    ->state(fn (EventActivity $record): string => self::dateLabel($record))
                    ->badge(fn (EventActivity $record): bool => ! $record->isScheduled())
                    ->color(fn (EventActivity $record): string => $record->isScheduled() ? 'gray' : 'warning')
                    ->sortable(),
                TextColumn::make('starts_at')
                    ->label(__('program.activities.columns.time').' ('.$event->timezone.')')
                    ->state(fn (EventActivity $record): string => self::timeLabel($record))
                    ->sortable(),
                TextColumn::make('categories.name')
                    ->label(__('program.activities.columns.categories'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('speakers')
                    ->label(__('program.activities.columns.speakers'))
                    ->state(fn (EventActivity $record): array => $record->speakers->map(fn (Speaker $speaker): string => $speaker->full_name)->all())
                    ->listWithLineBreaks()
                    ->limitList(3)
                    ->toggleable(),
                IconColumn::make('is_published')
                    ->label(__('program.activities.columns.published'))
                    ->boolean(),
                TextColumn::make('hidden_by_reversal_at')
                    ->label('')
                    ->state(fn (EventActivity $record): ?string => $record->hidden_by_reversal_at === null ? null : __('program.activities.hidden_by_reversal'))
                    ->badge()
                    ->color('danger'),
                TextColumn::make('submission.title')
                    ->label(__('program.activities.columns.origin'))
                    ->state(fn (EventActivity $record): ?string => self::originTitle($record))
                    ->url(fn (EventActivity $record): ?string => self::originTitle($record) === null ? null : EventResource::getUrl('submissions', ['record' => $record->event_id]))
                    ->limit(40),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('starts_on')->orderBy('starts_at')->orderBy('title'))
            ->filters([
                SelectFilter::make('category')
                    ->label(__('program.activities.filters.category'))
                    ->options(fn (): array => $event->activityCategories()->orderBy('position')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => $this->filterByCategory($query, $data['value'] ?? null)),
                TernaryFilter::make('is_published')
                    ->label(__('program.activities.filters.published'))
                    ->trueLabel(__('program.activities.filters.published_yes'))
                    ->falseLabel(__('program.activities.filters.published_no')),
                TernaryFilter::make('schedule')
                    ->label(__('program.activities.filters.schedule'))
                    ->trueLabel(__('program.activities.filters.scheduled'))
                    ->falseLabel(__('program.activities.filters.unscheduled'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('starts_on'),
                        false: fn (Builder $query): Builder => $query->whereNull('starts_on'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->paginationPageOptions([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->recordActions([$this->editAction(), $this->publicationAction(), $this->deleteAction()])
            ->emptyStateHeading(__('program.activities.empty_heading'))
            ->emptyStateDescription(__('program.activities.empty_description'));
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [$this->createAction()];
    }

    private function createAction(): Action
    {
        return Action::make('createActivity')
            ->label(__('program.activities.actions.create'))
            ->icon(Heroicon::OutlinedPlus)
            ->slideOver()
            ->schema(fn (): array => $this->fields(null))
            ->visible(fn (): bool => Gate::allows('create', [EventActivity::class, $this->event()]))
            ->action(fn (array $data, Schema $schema) => $this->run(
                fn () => app(CreateActivity::class)->handle(ActionErrors::actor(), $this->event(), $this->payload($data)),
                __('program.activities.messages.saved'),
                $data,
                $schema,
            ));
    }

    private function editAction(): Action
    {
        return Action::make('editActivity')
            ->label(__('program.activities.actions.edit'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->slideOver()
            ->schema(fn (Model $record): array => $this->fields($this->activity($record)))
            ->fillForm(fn (Model $record): array => $this->formState($this->activity($record)))
            ->visible(fn (Model $record): bool => Gate::allows('update', $record))
            ->action(fn (Model $record, array $data, Schema $schema) => $this->run(
                fn () => app(UpdateActivity::class)->handle(ActionErrors::actor(), $this->activity($record), $this->payload($data)),
                __('program.activities.messages.saved'),
                $data,
                $schema,
            ));
    }

    private function publicationAction(): Action
    {
        return Action::make('togglePublication')
            ->label(fn (Model $record): string => __($this->activity($record)->is_published ? 'program.activities.actions.unpublish' : 'program.activities.actions.publish'))
            ->icon(fn (Model $record): Heroicon => $this->activity($record)->is_published ? Heroicon::OutlinedEyeSlash : Heroicon::OutlinedGlobeAlt)
            ->visible(fn (Model $record): bool => Gate::allows('update', $record))
            ->action(function (Model $record): void {
                $activity = $this->activity($record);
                $publish = ! $activity->is_published;

                app(SetActivityPublication::class)->handle(ActionErrors::actor(), $activity, $publish);

                Notification::make()
                    ->title(__($publish ? 'program.activities.messages.published' : 'program.activities.messages.unpublished'))
                    ->success()
                    ->send();
            });
    }

    private function deleteAction(): Action
    {
        return Action::make('deleteActivity')
            ->label(__('program.activities.actions.delete'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('program.activities.actions.delete_confirm'))
            ->visible(fn (Model $record): bool => Gate::allows('delete', $record))
            ->action(function (Model $record): void {
                app(DeleteActivity::class)->handle(ActionErrors::actor(), $this->activity($record));

                Notification::make()->title(__('program.activities.messages.deleted'))->success()->send();
            });
    }

    /**
     * Campos del alta y la edición. En la edición muestran el archivo actual (por la ruta autenticada)
     * y dejan quitarlo; un archivo nuevo lo reemplaza. Fecha y hora siempre obligatorias (DA-4).
     *
     * @return list<mixed>
     */
    private function fields(?EventActivity $activity): array
    {
        return [
            TextInput::make('title')
                ->label(__('program.activities.form.title'))
                ->required()
                ->maxLength(200),
            Text::make(fn (): string => __('program.activities.form.unscheduled_help'))
                ->visible($activity !== null && ! $activity->isScheduled()),
            Text::make(__('program.activities.timezone_hint', ['timezone' => $this->event()->timezone])),
            Grid::make(['default' => 1, 'sm' => 2])->schema([
                DatePicker::make('starts_on')->label(__('program.activities.form.starts_on'))->required()->format('Y-m-d'),
                DatePicker::make('ends_on')->label(__('program.activities.form.ends_on'))->required()->format('Y-m-d'),
                TimePicker::make('starts_at')->label(__('program.activities.form.starts_at'))->required()->seconds(false),
                TimePicker::make('ends_at')->label(__('program.activities.form.ends_at'))->required()->seconds(false),
            ]),
            ...$this->fileFields($activity, 'icon', 256),
            ...$this->fileFields($activity, 'image', 5120),
            TextInput::make('summary')
                ->label(__('program.activities.form.summary'))
                ->maxLength(200),
            RichEditor::make('description')
                ->label(__('program.activities.form.description'))
                ->toolbarButtons([['bold', 'italic', 'link'], ['bulletList', 'orderedList'], ['undo', 'redo']]),
            Select::make('speaker_ids')
                ->label(__('program.activities.form.speakers'))
                ->multiple()
                ->preload()
                ->searchable()
                ->options(fn (): array => $this->speakerOptions()),
            Select::make('category_ids')
                ->label(__('program.activities.form.categories'))
                ->multiple()
                ->preload()
                ->options(fn (): array => $this->event()->activityCategories()->orderBy('position')->pluck('name', 'id')->all()),
            Toggle::make('is_published')
                ->label(__('program.activities.form.is_published'))
                ->default(true),
        ];
    }

    /**
     * Subida de un archivo (`icon` o `image`): el archivo llega sin guardar (`storeFiles(false)`) y la
     * Action lo valida por contenido y lo guarda. El tipo y el tope se repiten aquí solo para avisar antes.
     *
     * @return list<mixed>
     */
    private function fileFields(?EventActivity $activity, string $field, int $maxKilobytes): array
    {
        $path = $activity?->{"{$field}_path"};
        $fields = [];

        if ($activity !== null && is_string($path)) {
            $fields[] = Image::make(self::mediaUrl($activity, $field) ?? '', __("program.activities.form.{$field}").': '.__('program.activities.form.current_file'))
                ->imageHeight($field === 'icon' ? '3rem' : '8rem');
            $fields[] = Checkbox::make("remove_{$field}")->label(__("program.activities.form.remove_{$field}"));
        }

        $fields[] = FileUpload::make($field)
            ->label(__("program.activities.form.{$field}"))
            ->helperText(__("program.activities.form.{$field}_help"))
            ->disk(MediaDisk::name())
            ->visibility('private')
            ->storeFiles(false)
            ->image()
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->maxSize($maxKilobytes);

        return $fields;
    }

    /** @return array<string, mixed> */
    private function formState(EventActivity $activity): array
    {
        return [
            'title' => $activity->title,
            'starts_on' => $activity->starts_on?->toDateString(),
            'ends_on' => $activity->ends_on?->toDateString(),
            'starts_at' => $activity->starts_at === null ? null : substr($activity->starts_at, 0, 5),
            'ends_at' => $activity->ends_at === null ? null : substr($activity->ends_at, 0, 5),
            'summary' => $activity->summary,
            'description' => $activity->description,
            'category_ids' => $activity->categories->pluck('id')->all(),
            'speaker_ids' => $activity->speakers->pluck('id')->all(),
            'is_published' => $activity->is_published,
        ];
    }

    /**
     * Datos del formulario para la Action: solo archivos nuevos como archivos (una ruta enviada por el
     * cliente no se acepta) y sin valores vacíos de los campos opcionales de archivo.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payload(array $data): array
    {
        foreach (['icon', 'image'] as $field) {
            if (! ($data[$field] ?? null) instanceof UploadedFile) {
                unset($data[$field]);
            }
        }

        return $data;
    }

    /**
     * Ejecuta la Action de negocio. Un error de validación se pinta junto a su campo; los archivos
     * temporales de Livewire se borran al terminar, con éxito o sin él.
     *
     * @param  callable(): mixed  $operation
     * @param  array<string, mixed>  $data
     */
    private function run(callable $operation, string $successTitle, array $data, Schema $schema): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            // Un error por ponente elegido se muestra junto al selector, que es un solo campo.
            $errors = [];

            foreach ($exception->errors() as $field => $messages) {
                $key = str_starts_with($field, 'speaker_ids.') ? 'speaker_ids' : $field;
                $errors[$key] = [...($errors[$key] ?? []), ...$messages];
            }

            throw ActionErrors::forForm(ValidationException::withMessages($errors), $schema->getStatePath());
        } finally {
            foreach (['icon', 'image'] as $field) {
                if (($data[$field] ?? null) instanceof TemporaryUploadedFile) {
                    $data[$field]->delete();
                }
            }
        }

        Notification::make()->title($successTitle)->success()->send();
    }

    /**
     * @param  Builder<EventActivity>  $query
     * @return Builder<EventActivity>
     */
    private function filterByCategory(Builder $query, mixed $value): Builder
    {
        if (blank($value)) {
            return $query;
        }

        // Un valor que no sea una categoría de este evento no coincide con nada (y evita un identificador mal formado en la consulta).
        if (! is_string($value) || ! Str::isUuid($value)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('categories', fn (Builder $categories): Builder => $categories
            ->where('activity_categories.event_id', $this->event()->id)
            ->whereKey($value));
    }

    private static function dateLabel(EventActivity $activity): string
    {
        if ($activity->starts_on === null || $activity->ends_on === null) {
            return __('program.activities.unscheduled');
        }

        $start = $activity->starts_on->locale(app()->getLocale())->isoFormat('ll');

        return $activity->starts_on->equalTo($activity->ends_on)
            ? $start
            : $start.' – '.$activity->ends_on->locale(app()->getLocale())->isoFormat('ll');
    }

    private static function timeLabel(EventActivity $activity): string
    {
        if ($activity->starts_at === null || $activity->ends_at === null) {
            return '—';
        }

        $format = fn (string $time): string => Carbon::createFromFormat('H:i:s', $time)?->locale(app()->getLocale())->isoFormat('LT') ?? $time;

        return $format($activity->starts_at).' – '.$format($activity->ends_at);
    }

    /** Título de la propuesta de origen, solo si quien mira puede verla (`SubmissionPolicy`). */
    private static function originTitle(EventActivity $activity): ?string
    {
        $submission = $activity->submission;

        return $submission !== null && Gate::allows('view', $submission) ? $submission->title : null;
    }

    /** Miniatura incrustada del archivo de la actividad (como la del ponente); nula si no tiene ese archivo. */
    private static function mediaUrl(EventActivity $activity, string $type): ?string
    {
        return MediaThumbnail::dataUri($type === 'icon' ? $activity->icon_path : $activity->image_path, $type === 'icon' ? 96 : 160);
    }

    /** @return array<string, string> ponentes DEL EVENTO (id => nombre), por apellido */
    private function speakerOptions(): array
    {
        return $this->event()->speakers()
            ->orderBy('last_name')->orderBy('first_name')
            ->get()
            ->mapWithKeys(fn (Speaker $speaker): array => [$speaker->id => $speaker->full_name])
            ->all();
    }

    private function activity(Model $record): EventActivity
    {
        return $record instanceof EventActivity ? $record : throw new LogicException('La fila no es una actividad.');
    }

    private function event(): Event
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof Event ? $owner : throw new LogicException('Las actividades necesitan un evento.');
    }
}
