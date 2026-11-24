<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\RelationManagers;

use App\Actions\Calls\ChangeCallStatus;
use App\Actions\Calls\CreateCallForSubmissions;
use App\Actions\Calls\CreateSubmissionType;
use App\Actions\Calls\PublishCall;
use App\Actions\Calls\UnassignFormFromCall;
use App\Actions\Calls\UpdateCallForSubmissions;
use App\Actions\Forms\PublishFormDefinition;
use App\Enums\CallState;
use App\Enums\CallStatus;
use App\Enums\FormDefinitionStatus;
use App\Filament\Resources\Events\Actions\LandingActions;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Support\ActionErrors;
use App\Filament\Support\LocalizedFields;
use App\Filament\Support\SubmissionTypeFields;
use App\Http\Requests\Calls\StoreCallForSubmissionsRequest;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\FormDefinition;
use App\Models\Submission;
use App\Models\SubmissionType;
use App\Support\Calls\ResolveCallState;
use App\Support\Events\EventTime;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Js;
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
                    ->weight('medium')
                    // Enlace a la página pública; un borrador no la tiene (responde 404) y queda como texto.
                    ->url(fn (CallForSubmissions $record): ?string => $this->hasPublicPage($record) ? $this->publicUrl($record) : null)
                    ->openUrlInNewTab(),
                TextColumn::make('submission_type_id')
                    ->label(__('calls.columns.type'))
                    ->state(fn (CallForSubmissions $record): string => (string) $record->submissionType?->label($this->owner()->default_locale))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('form_definition_id')
                    ->label(__('calls.columns.form'))
                    ->state(fn (CallForSubmissions $record): string => data_get($record, 'formDefinition.name') ?? __('calls.form.none'))
                    ->badge(fn (CallForSubmissions $record): bool => $record->form_definition_id === null)
                    ->color(fn (CallForSubmissions $record): string => $record->form_definition_id === null ? 'danger' : 'gray'),
                // Un solo distintivo: el estado público (lo que ve quien envía); el fijado a mano va en el tooltip.
                TextColumn::make('status')
                    ->label(__('calls.columns.status'))
                    ->state(fn (CallForSubmissions $record): string => $this->statusLabel($record))
                    ->badge()
                    ->color(fn (CallForSubmissions $record): string => $this->statusColor($record))
                    ->tooltip(fn (CallForSubmissions $record): ?string => ResolveCallState::for($record) === null ? null : $record->status->getLabel()),
                TextColumn::make('opens_at')
                    ->label(__('calls.columns.period'))
                    ->state(fn (CallForSubmissions $record): array => $this->periodLines($record))
                    ->listWithLineBreaks()
                    ->tooltip(__('calls.period_timezone', ['timezone' => $this->owner()->timezone])),
            ])
            // Sin N+1: el tipo y el formulario de toda la página se cargan de una vez (el modelo es estricto).
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['submissionType', 'formDefinition']))
            ->defaultSort('opens_at', 'desc')
            ->headerActions([$this->createAction()])
            ->recordActions([
                $this->editAction(),
                $this->publishAction(),
                $this->assignFormAction(),
                // El resto va en un menú «⋮»: seis enlaces por fila desbordaban la tabla.
                ActionGroup::make([
                    $this->unassignFormAction(),
                    $this->statusAction('pauseCall', 'pause', 'paused', CallStatus::Paused, [CallStatus::Published], Heroicon::OutlinedPauseCircle, 'warning'),
                    $this->statusAction('resumeCall', 'resume', 'resumed', CallStatus::Published, [CallStatus::Paused], Heroicon::OutlinedPlayCircle, 'success', true),
                    $this->statusAction('closeCall', 'close', 'closed', CallStatus::Closed, [CallStatus::Published, CallStatus::Paused], Heroicon::OutlinedLockClosed, 'danger', false, true),
                    $this->statusAction('reopenCall', 'reopen', 'reopened', CallStatus::Published, [CallStatus::Closed], Heroicon::OutlinedArrowPath, 'success', true),
                    $this->viewPublicPageAction(),
                    LandingActions::designFor(fn (): Event => $this->owner()),
                    $this->copyPublicLinkAction(),
                ])->label(__('calls.actions.more'))->iconButton(),
            ])
            ->emptyStateHeading(__('calls.empty_heading'))
            ->emptyStateDescription(__('calls.empty_description'));
    }

    private function createAction(): Action
    {
        return Action::make('createCall')
            ->label(__('calls.new_call'))
            ->icon(Heroicon::OutlinedPlus)
            ->schema(fn (): array => [...$this->formFields(), ...($this->canManageForms() ? [$this->formSelect()] : [])])
            // Más de cuatro campos (tipo, idiomas, textos y fechas): panel lateral (plan 015, DA-5).
            ->slideOver()
            ->visible(fn (): bool => Gate::allows('create', [CallForSubmissions::class, $this->owner()]))
            ->action(function (array $data): void {
                $formId = $data['form_definition_id'] ?? null;
                unset($data['form_definition_id']);

                $call = null;
                $created = $this->run(function () use (&$call, $data): void {
                    $call = app(CreateCallForSubmissions::class)->handle(ActionErrors::actor(), $this->owner(), $data);
                }, __('calls.actions.saved'));

                // El alta ya está hecha: si la asignación falla, se avisa y la convocatoria queda sin formulario.
                if ($created && $call instanceof CallForSubmissions && is_string($formId)) {
                    $this->run(
                        fn () => $this->assignForm($formId, $call),
                        __('calls.form.assigned'),
                        __('calls.form.assign_failed_on_create'),
                    );
                }
            });
    }

    /** Asignar o cambiar el formulario (plan 025): la Action publica el borrador y valida el evento. */
    private function assignFormAction(): Action
    {
        $label = fn (CallForSubmissions $record): string => __($record->form_definition_id === null ? 'calls.form.assign' : 'calls.form.change');

        return Action::make('assignForm')
            ->label($label)
            ->modalHeading($label)
            ->icon(Heroicon::OutlinedDocumentText)
            ->fillForm(fn (CallForSubmissions $record): array => ['form_definition_id' => $record->form_definition_id])
            ->schema(fn (CallForSubmissions $record): array => [
                $this->formSelect(optional: false)->required()->live(),
                Text::make(__('calls.form.draft_notice'))
                    ->key('draft_notice')
                    ->color('warning')
                    ->visible(fn (Get $get): bool => $this->isDraftForm($get('form_definition_id'))),
                Text::make(__('calls.form.submissions_notice'))
                    ->key('submissions_notice')
                    ->color('warning')
                    ->visible(fn (): bool => Submission::query()->where('call_for_submissions_id', $record->id)->exists()),
                Actions::make([
                    Action::make('createForm')
                        ->label(__('calls.form.create_new'))
                        ->icon(Heroicon::OutlinedPlus)
                        ->link()
                        ->url(fn (): string => EventResource::getUrl('form-definitions', ['record' => $this->owner()])),
                ]),
            ])
            ->visible(fn (): bool => $this->canManageForms())
            ->action(fn (CallForSubmissions $record, array $data) => $this->run(
                fn () => $this->assignForm((string) $data['form_definition_id'], $record),
                __('calls.form.assigned'),
            ));
    }

    /** Quitar el formulario: solo en borrador (la Action lo exige de nuevo). */
    private function unassignFormAction(): Action
    {
        return Action::make('unassignForm')
            ->label(__('calls.form.unassign'))
            ->icon(Heroicon::OutlinedXMark)
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(__('calls.form.unassign_confirm'))
            ->visible(fn (CallForSubmissions $record): bool => $record->status === CallStatus::Draft
                && $record->form_definition_id !== null
                && Gate::allows('update', $record))
            ->action(fn (CallForSubmissions $record) => $this->run(
                fn () => app(UnassignFormFromCall::class)->handle(ActionErrors::actor(), $record),
                __('calls.form.unassigned'),
            ));
    }

    /** Campo con los formularios del evento; la ayuda dice «Opcional» solo cuando el campo lo es. */
    private function formSelect(bool $optional = true): Select
    {
        return Select::make('form_definition_id')
            ->label(__('calls.form.field'))
            ->helperText(__($optional ? 'calls.form.field_help' : 'calls.form.field_help_assign'))
            ->options(fn (): array => $this->formOptions());
    }

    /**
     * Asignar formularios exige `forms.manage`. El campo del alta se incluye o no al armar el esquema,
     * y no con `visible()`: una visibilidad con closure en el modal impide que las pruebas de Filament envíen el alta.
     */
    private function canManageForms(): bool
    {
        return Gate::allows('create', [FormDefinition::class, $this->owner()]);
    }

    /** @return array<string, string> formularios del evento con su estado y versión */
    private function formOptions(): array
    {
        return FormDefinition::query()
            ->where('event_id', $this->owner()->id)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (FormDefinition $form): array => [$form->id => __('calls.form.option', [
                'name' => $form->name,
                'status' => __("forms.status.{$form->status->value}"),
                'version' => $form->version,
            ])])
            ->all();
    }

    private function isDraftForm(mixed $formId): bool
    {
        return is_string($formId)
            && FormDefinition::query()->whereKey($formId)->where('status', FormDefinitionStatus::Draft)->exists();
    }

    private function assignForm(string $formId, CallForSubmissions $call): void
    {
        app(PublishFormDefinition::class)->handle(ActionErrors::actor(), FormDefinition::query()->findOrFail($formId), $call);
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
                'show_back_link' => $record->show_back_link,
                'extra_links' => $record->extra_links ?? [],
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

    /** Abre la página pública tal como la ve un visitante (CA-004-37). */
    private function viewPublicPageAction(): Action
    {
        return Action::make('viewPublicPage')
            ->label(__('calls.actions.view_public'))
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->color('gray')
            ->url(fn (CallForSubmissions $record): string => $this->publicUrl($record))
            ->openUrlInNewTab()
            ->visible(fn (CallForSubmissions $record): bool => $this->hasPublicPage($record));
    }

    /** Copia la URL pública al portapapeles, sin ida al servidor. */
    private function copyPublicLinkAction(): Action
    {
        return Action::make('copyPublicLink')
            ->label(__('calls.actions.copy_link'))
            ->icon(Heroicon::OutlinedClipboardDocument)
            ->color('gray')
            ->alpineClickHandler(fn (CallForSubmissions $record): string => 'window.navigator.clipboard.writeText('.Js::from($this->publicUrl($record))->toHtml().'); '
                .'$tooltip('.Js::from(__('calls.actions.link_copied'))->toHtml().', { theme: $store.theme, timeout: 2000 })')
            ->visible(fn (CallForSubmissions $record): bool => $this->hasPublicPage($record));
    }

    /** Un borrador responde 404 en la ruta pública (CA-004-20), así que no se ofrece su enlace (CA-004-38). */
    private function hasPublicPage(CallForSubmissions $call): bool
    {
        return $call->status !== CallStatus::Draft;
    }

    /** Siempre con el contexto y el evento actuales, nunca con datos de otra organización (CA-004-40). */
    private function publicUrl(CallForSubmissions $call): string
    {
        return route('calls.show', [
            'organization' => CurrentOrganization::get()->slug,
            'event' => $this->owner()->slug,
            'call' => $call->slug,
        ]);
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
                    ->format('Y-m-d H:i')
                    ->displayFormat($this->pickerFormat()),
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

        $texts = LocalizedFields::for($this->owner(), static fn (string $locale): array => [
            TextInput::make("title.{$locale}")
                ->label(__("calls.fields.title_{$locale}"))
                ->maxLength(255),
            Textarea::make("description.{$locale}")
                ->label(__("calls.fields.description_{$locale}"))
                ->maxLength(5000)
                ->rows(3),
        ]);

        return [
            ...$fields,
            ...$texts,
            DateTimePicker::make('opens_at')->label(__('calls.fields.opens_at'))->seconds(false)->format('Y-m-d H:i')->displayFormat($this->pickerFormat())->required(),
            DateTimePicker::make('closes_at')->label(__('calls.fields.closes_at'))->seconds(false)->format('Y-m-d H:i')->displayFormat($this->pickerFormat())->required(),
            DateTimePicker::make('results_at')->label(__('calls.fields.results_at'))->seconds(false)->format('Y-m-d H:i')->displayFormat($this->pickerFormat()),
            // Enlaces del pie de la página pública.
            Toggle::make('show_back_link')
                ->label(__('calls.fields.show_back_link'))
                ->helperText(__('calls.fields.show_back_link_help'))
                ->default(true),
            Repeater::make('extra_links')
                ->label(__('calls.fields.extra_links'))
                ->helperText(__('calls.fields.extra_links_help'))
                ->addActionLabel(__('calls.fields.extra_links_add'))
                ->maxItems(StoreCallForSubmissionsRequest::MAX_EXTRA_LINKS)
                ->defaultItems(0)
                ->collapsible()
                ->itemLabel(fn (array $state): ?string => is_string($state['label'] ?? null) && $state['label'] !== '' ? $state['label'] : null)
                ->schema([
                    TextInput::make('label')
                        ->label(__('calls.fields.extra_link_label'))
                        ->maxLength(100),
                    TextInput::make('url')
                        ->label(__('calls.fields.extra_link_url'))
                        ->placeholder('https://')
                        ->url()
                        ->maxLength(2048),
                ]),
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

    /** Estado público calculado con las fechas; un borrador no tiene y muestra su propio estado. */
    private function statusLabel(CallForSubmissions $call): string
    {
        $state = ResolveCallState::for($call);

        return $state === null ? $call->status->getLabel() : __('calls.state.'.$state->value);
    }

    private function statusColor(CallForSubmissions $call): string
    {
        return match (ResolveCallState::for($call)) {
            null => $call->status->getColor(),
            CallState::Scheduled => 'info',
            CallState::Open => 'success',
            CallState::Paused => 'warning',
            CallState::Closed => 'danger',
        };
    }

    /** @return list<string> apertura y cierre, uno por línea, en la hora del evento */
    private function periodLines(CallForSubmissions $call): array
    {
        $time = EventTime::for($this->owner());
        $format = __('calls.list_date_format');

        return [
            __('calls.period_opens', ['date' => $time->dateTime($call->opens_at, $format, ' · ')]),
            __('calls.period_closes', ['date' => $time->dateTime($call->closes_at, $format, ' · ')]),
        ];
    }

    /** Instante UTC → hora local de la zona del evento. */
    /** Cómo MUESTRAN la fecha y la hora los selectores: con el formato de hora del evento (plan 023, DA-3). */
    private function pickerFormat(): string
    {
        return $this->owner()->time_format->displayDateTime(__('calls.date_format'));
    }

    private function localTime(\DateTimeInterface $instant, string $format = 'Y-m-d H:i'): string
    {
        return CarbonImmutable::instance($instant)->setTimezone($this->owner()->timezone)->format($format);
    }

    private function owner(): Event
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof Event ? $owner : throw new \LogicException('El gestor de convocatorias necesita un evento.');
    }

    /**
     * @param  callable(): mixed  $operation
     * @return bool si la operación terminó sin error de validación
     */
    private function run(callable $operation, string $successTitle, ?string $failureTitle = null): bool
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            Notification::make()
                ->title($failureTitle ?? __('calls.actions.failed'))
                ->body(ActionErrors::summary($exception))
                ->danger()
                ->send();

            return false;
        }

        Notification::make()->title($successTitle)->success()->send();

        return true;
    }
}
