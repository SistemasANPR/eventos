<?php

declare(strict_types=1);

namespace App\Filament\Resources\Forms\Pages;

use App\Actions\Forms\PublishFormDefinition;
use App\Actions\Forms\UpdateFormDefinition;
use App\Exceptions\Domain\FieldHasAnswers;
use App\Exceptions\Domain\FormChangeNotAllowed;
use App\Exceptions\Domain\FormVersionConflict;
use App\Filament\Resources\Forms\FormDefinitionResource;
use App\Filament\Support\ActionErrors;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\FormDefinition;
use App\Support\Forms\FormSettings;
use App\Support\Forms\ProposalFields;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use LogicException;

class EditFormDefinition extends EditRecord
{
    protected static string $resource = FormDefinitionResource::class;

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            // Guardar y cancelar arriba: el formulario es largo y así no hay que bajar hasta el final.
            $this->getSaveFormAction()->formId('form'),
            $this->getCancelFormAction()->formId('form'),
            Action::make('preview')
                ->label(__('forms.preview.action'))
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->url(fn (): string => FormDefinitionResource::getUrl('preview', [
                    'event' => $this->formOf($this->getRecord())->event_id,
                    'record' => $this->getRecord(),
                ])),
            $this->publishAction(),
        ];
    }

    /** Los botones de guardar y cancelar van en la cabecera, no al final del formulario. */
    protected function getFormActions(): array
    {
        return [];
    }

    /**
     * Un formulario anterior a los ajustes (nulo) se abre con lo que siempre ha hecho: todo visible, los mismos
     * obligatorios de siempre y los participantes primero.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : null;
        $data['settings'] = [
            ...($settings ?? []),
            'participants_position' => FormSettings::position($settings),
            'participant_fields' => FormSettings::participantFields($settings),
        ];

        // Un formulario anterior no trae el título ni el tema de la propuesta: se muestran al principio de los campos
        // y quedan guardados al guardar. Mientras no se guarde, el formulario público conserva su bloque fijo.
        $event = Event::query()->find($data['event_id'] ?? null);

        if ($event instanceof Event && is_array($data['fields'] ?? null)) {
            $data['fields'] = ProposalFields::withDefaults(array_values($data['fields']), $event);
        }

        return $data;
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $form = $this->formOf($record);

        try {
            return app(UpdateFormDefinition::class)->handle(ActionErrors::actor(), $form, $data);
        } catch (ValidationException $exception) {
            throw ActionErrors::forForm($exception);
        } catch (FormVersionConflict|FieldHasAnswers|FormChangeNotAllowed $exception) {
            $this->failWith($exception);
        }
    }

    /** Publica el formulario y lo asocia a una convocatoria de su evento (CA-005-16). */
    private function publishAction(): Action
    {
        return Action::make('publish')
            ->label(__('forms.actions.publish'))
            ->icon('heroicon-o-paper-airplane')
            ->modalHeading(__('forms.actions.publish'))
            ->modalDescription(__('forms.actions.publish_help'))
            ->schema([
                Select::make('call_id')
                    ->label(__('forms.actions.call'))
                    ->options(fn (): array => $this->callOptions())
                    ->required(),
            ])
            ->action(function (array $data): void {
                $form = $this->formOf($this->getRecord());
                $call = CallForSubmissions::query()->findOrFail($data['call_id']);

                try {
                    app(PublishFormDefinition::class)->handle(ActionErrors::actor(), $form, $call);
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title(ActionErrors::summary($exception))->send();

                    return;
                }

                $this->refreshFormData(['status', 'published_at']);
                Notification::make()->success()->title(__('forms.actions.published'))->send();
            });
    }

    /** @return array<string, string> convocatorias del mismo evento del formulario */
    private function callOptions(): array
    {
        return CallForSubmissions::query()
            ->where('event_id', $this->formOf($this->getRecord())->event_id)
            ->orderBy('opens_at')
            ->get()
            ->mapWithKeys(fn (CallForSubmissions $call): array => [$call->id => (string) $call->getTranslation('title', $call->locales[0] ?? 'es', true)])
            ->all();
    }

    private function formOf(Model $record): FormDefinition
    {
        return $record instanceof FormDefinition ? $record : throw new LogicException('La página de edición no tiene un formulario.');
    }

    /** Muestra el motivo en una notificación y detiene el guardado sin perder lo escrito. */
    private function failWith(FormVersionConflict|FieldHasAnswers|FormChangeNotAllowed $exception): never
    {
        Notification::make()
            ->danger()
            ->title(__('forms.errors.'.strtolower($exception::CODE)))
            ->send();

        throw new Halt;
    }
}
