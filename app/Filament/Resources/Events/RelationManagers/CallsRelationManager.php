<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\RelationManagers;

use App\Actions\Calls\CreateCallForSubmissions;
use App\Actions\Calls\PublishCall;
use App\Actions\Calls\UpdateCallForSubmissions;
use App\Enums\SubmissionType;
use App\Filament\Support\ActionErrors;
use App\Http\Requests\Events\StoreEventRequest;
use App\Models\CallForSubmissions;
use App\Models\Event;
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
                TextColumn::make('submission_type')->label(__('calls.columns.type'))->badge()->color('gray'),
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
            ->recordActions([$this->editAction(), $this->publishAction()])
            ->emptyStateHeading(__('calls.empty_heading'))
            ->emptyStateDescription(__('calls.empty_description'));
    }

    private function createAction(): Action
    {
        return Action::make('createCall')
            ->label(__('calls.new_call'))
            ->icon(Heroicon::OutlinedPlus)
            ->schema(fn (): array => $this->formFields())
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
            ->fillForm(fn (CallForSubmissions $record): array => [
                'submission_type' => $record->submission_type->value,
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
     * El formulario trabaja en la hora local del evento; el servidor valida de nuevo todo.
     *
     * @return list<mixed>
     */
    private function formFields(): array
    {
        $fields = [
            Select::make('submission_type')
                ->label(__('calls.fields.submission_type'))
                ->options(SubmissionType::class)
                ->required(),
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
