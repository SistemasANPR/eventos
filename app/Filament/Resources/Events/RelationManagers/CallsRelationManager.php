<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\RelationManagers;

use App\Actions\Calls\ChangeCallStatus;
use App\Actions\Calls\CreateCallForSubmissions;
use App\Actions\Calls\CreateSubmissionType;
use App\Actions\Calls\PublishCall;
use App\Actions\Calls\UpdateCallForSubmissions;
use App\Enums\CallStatus;
use App\Filament\Support\ActionErrors;
use App\Filament\Support\SubmissionTypeFields;
use App\Http\Requests\Events\StoreEventRequest;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\SubmissionType;
use App\Support\Calls\ResolveCallState;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Convocatorias DENTRO del evento (plan 004, H-004-1): lista, alta, edición y
 * publicación. Delgado: las reglas viven en las Actions y las Policies deciden;
 * no hay forma de borrar una convocatoria. Las fechas se capturan y se muestran
 * en la hora local del evento y se guardan en UTC.
 */
class CallsRelationManager extends RelationManager
{
    protected static string $relationship = 'calls';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('calls.relation_title');
    }

    /** Solo quien consulta o gestiona convocatorias ve la sección (el revisor no). */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('viewAny', CallForSubmissions::class);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label(__('calls.columns.title'))
                    ->state(fn (CallForSubmissions $record): string => (string) $record->getTranslation('title', $this->owner()->default_locale, true))
                    ->weight('medium'),
                TextColumn::make('submission_type_id')
                    ->label(__('calls.columns.type'))
                    ->state(fn (CallForSubmissions $record): string => (string) $record->submissionType?->label($this->owner()->default_locale))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('status')->label(__('calls.columns.status'))->badge(),
                TextColumn::make('public_state')
                    ->label(__('calls.columns.public_state'))
                    ->state(fn (CallForSubmissions $record): string => $this->publicStateLabel($record)),
                TextColumn::make('opens_at')
                    ->label(__('calls.columns.period'))
                    ->state(fn (CallForSubmissions $record): string => $this->localTime($record->opens_at).' – '.$this->localTime($record->closes_at)),
            ])
            ->defaultSort('opens_at', 'desc')
            ->headerActions([$this->createAction()])
            ->recordActions([
                $this->editAction(),
                $this->publishAction(),
                $this->statusAction('pauseCall', 'pause', 'paused', CallStatus::Paused, [CallStatus::Published], Heroicon::OutlinedPauseCircle, 'warning'),
                $this->statusAction('resumeCall', 'resume', 'resumed', CallStatus::Published, [CallStatus::Paused], Heroicon::OutlinedPlayCircle, 'success', true),
                $this->statusAction('closeCall', 'close', 'closed', CallStatus::Closed, [CallStatus::Published, CallStatus::Paused], Heroicon::OutlinedLockClosed, 'danger', false, true),
                $this->statusAction('reopenCall', 'reopen', 'reopened', CallStatus::Published, [CallStatus::Closed], Heroicon::OutlinedArrowPath, 'success', true),
            ])
            ->emptyStateHeading(__('calls.empty_heading'))
            ->emptyStateDescription(__('calls.empty_description'));
    }

    private function createAction(): Action
    {
        return Action::make('createCall')
            ->label(__('calls.new_call'))
            ->icon(Heroicon::OutlinedPlus)
            ->schema(fn (): array => $this->formFields())
            // Más de cuatro campos (tipo, idiomas, textos y fechas): panel lateral (plan 015, DA-5).
            ->slideOver()
            ->visible(fn (): bool => Gate::allows('create', [CallForSubmissions::class, $this->owner()]))
            ->action(fn (array $data) => $this->run(
                fn () => app(CreateCallForSubmissions::class)->handle(ActionErrors::actor(), $this->owner(), $data),
                __('calls.actions.saved'),
            ));
    }

    private function editAction(): Action
    {
        return Action::make('editCall')
            ->label(__('calls.actions.edit'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->schema(fn (): array => $this->formFields())
            ->slideOver()
            ->fillForm(fn (CallForSubmissions $record): array => [
                'submission_type_id' => $record->submission_type_id,
                'title' => $record->getTranslations('title'),
                'description' => $record->getTranslations('description'),
                'locales' => $record->locales,
                'opens_at' => $this->localTime($record->opens_at, 'Y-m-d H:i'),
                'closes_at' => $this->localTime($record->closes_at, 'Y-m-d H:i'),
                'results_at' => $record->results_at === null ? null : $this->localTime($record->results_at, 'Y-m-d H:i'),
            ])
            ->visible(fn (CallForSubmissions $record): bool => Gate::allows('update', $record))
            ->action(fn (CallForSubmissions $record, array $data) => $this->run(
                fn () => app(UpdateCallForSubmissions::class)->handle(ActionErrors::actor(), $record, $data),
                __('calls.actions.saved'),
            ));
    }

    private function publishAction(): Action
    {
        return Action::make('publish')
            ->label(__('calls.actions.publish'))
            ->icon(Heroicon::OutlinedGlobeAlt)
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(__('calls.actions.publish_confirm'))
            ->visible(fn (CallForSubmissions $record): bool => $record->status->value === 'draft' && Gate::allows('publish', $record))
            ->action(fn (CallForSubmissions $record) => $this->run(
                fn () => app(PublishCall::class)->handle(ActionErrors::actor(), $record),
                __('calls.actions.published'),
            ));
    }

    /**
     * Pausar, cerrar, reanudar y reabrir (H-004-4): visibles solo según el estado y el permiso;
     * la Action valida de nuevo. Al volver a publicada se ofrece una nueva fecha de cierre.
     *
     * @param  list<CallStatus>  $visibleFrom  estados desde los que se ofrece la acción
     */
    private function statusAction(
        string $name,
        string $labelKey,
        string $doneKey,
        CallStatus $target,
        array $visibleFrom,
        Heroicon $icon,
        string $color,
        bool $offersNewClose = false,
        bool $confirms = false,
    ): Action {
        $action = Action::make($name)
            ->label(__("calls.actions.{$labelKey}"))
            ->icon($icon)
            ->color($color)
            ->visible(fn (CallForSubmissions $record): bool => in_array($record->status, $visibleFrom, true) && Gate::allows('changeStatus', $record))
            ->action(fn (CallForSubmissions $record, array $data) => $this->run(
                fn () => app(ChangeCallStatus::class)->handle(ActionErrors::actor(), $record, $target, $data),
                __("calls.actions.{$doneKey}"),
            ));

        if ($offersNewClose) {
            $action->schema([
                DateTimePicker::make('closes_at')
                    ->label(__('calls.actions.new_close'))
                    ->helperText(__('calls.actions.new_close_help'))
                    ->seconds(false)
                    ->format('Y-m-d H:i'),
            ]);
        }

        if ($confirms) {
            $action->requiresConfirmation()->modalDescription(__('calls.actions.close_confirm'));
        }

        return $action;
    }

    /**
     * El formulario trabaja en la hora local del evento; el servidor valida de nuevo todo.
     *
     * @return list<mixed>
     */
    private function formFields(): array
    {
        $fields = [
            Select::make('submission_type_id')
                ->label(__('calls.fields.submission_type'))
                ->options(fn (mixed $state): array => $this->typeOptions(is_string($state) ? $state : null))
                ->required()
                ->createOptionForm(fn (): array => SubmissionTypeFields::names($this->owner()))
                ->createOptionModalHeading(__('calls.type_create_heading'))
                ->createOptionUsing(fn (array $data): string => app(CreateSubmissionType::class)->handle(ActionErrors::actor(), $this->owner(), $data)->id)
                ->createOptionAction(fn (Action $action): Action => $action
                    ->tooltip(__('calls.type_create'))
                    ->visible(fn (): bool => Gate::allows('create', [SubmissionType::class, $this->owner()]))),
            CheckboxList::make('locales')
                ->label(__('calls.fields.locales'))
                ->options(fn (): array => $this->localeOptions())
                ->required(),
        ];

        foreach (StoreEventRequest::LOCALES as $locale) {
            $isOffered = fn (): bool => in_array($locale, $this->owner()->locales, true);

            $fields[] = TextInput::make("title.{$locale}")
                ->label(__("calls.fields.title_{$locale}"))
                ->maxLength(255)
                ->visible($isOffered);
            $fields[] = Textarea::make("description.{$locale}")
                ->label(__("calls.fields.description_{$locale}"))
                ->maxLength(5000)
                ->rows(3)
                ->visible($isOffered);
        }

        return [
            ...$fields,
            DateTimePicker::make('opens_at')->label(__('calls.fields.opens_at'))->seconds(false)->format('Y-m-d H:i')->required(),
            DateTimePicker::make('closes_at')->label(__('calls.fields.closes_at'))->seconds(false)->format('Y-m-d H:i')->required(),
            DateTimePicker::make('results_at')->label(__('calls.fields.results_at'))->seconds(false)->format('Y-m-d H:i'),
        ];
    }

    /**
     * Tipos activos del evento en su orden, más el que la convocatoria ya tiene aunque esté archivado.
     *
     * @return array<string, string>
     */
    private function typeOptions(?string $currentId): array
    {
        $locale = $this->owner()->default_locale;

        return SubmissionType::query()
            ->where('event_id', $this->owner()->id)
            ->where(fn ($query) => $query->whereNull('archived_at')->orWhere('id', $currentId))
            ->orderBy('position')
            ->get()
            ->mapWithKeys(fn (SubmissionType $type): array => [$type->id => $type->label($locale)])
            ->all();
    }

    /** @return array<string, string> */
    private function localeOptions(): array
    {
        $names = ['es' => 'Español', 'en' => 'English', 'pt' => 'Português'];

        return array_intersect_key($names, array_flip($this->owner()->locales));
    }

    /** Estado público calculado con las fechas; un borrador no tiene (se muestra «—»). */
    private function publicStateLabel(CallForSubmissions $call): string
    {
        $state = ResolveCallState::for($call);

        return $state === null ? '—' : __('calls.state.'.$state->value);
    }

    /** Instante UTC → hora local de la zona del evento. */
    private function localTime(\DateTimeInterface $instant, string $format = 'Y-m-d H:i'): string
    {
        return CarbonImmutable::instance($instant)->setTimezone($this->owner()->timezone)->format($format);
    }

    private function owner(): Event
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof Event ? $owner : throw new \LogicException('El gestor de convocatorias necesita un evento.');
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
