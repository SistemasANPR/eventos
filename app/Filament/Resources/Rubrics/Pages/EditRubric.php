<?php

declare(strict_types=1);

namespace App\Filament\Resources\Rubrics\Pages;

use App\Actions\Reviews\PublishRubric;
use App\Actions\Reviews\SaveRubric;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Rubrics\RubricResource;
use App\Filament\Resources\Rubrics\Schemas\RubricFormData;
use App\Filament\Support\ActionErrors;
use App\Models\Event;
use App\Models\Rubric;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Edición de una rúbrica (plan 011, F4b). `SaveRubric` decide si se edita en sitio o si, por tener
 * evaluaciones registradas, se crea una versión nueva en borrador y la anterior queda intacta (DA-2, CA-011-3).
 */
class EditRubric extends EditRecord
{
    protected static string $resource = RubricResource::class;

    /** Versión nueva creada por el último guardado, para avisarlo; nulo si se editó en sitio. */
    private ?int $newVersion = null;

    public function getTitle(): string
    {
        return __('rubrics.edit_title');
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('publish')
                ->label(__('rubrics.actions.publish'))
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->requiresConfirmation()
                ->modalDescription(__('rubrics.actions.publish_help'))
                ->visible(fn (): bool => $this->rubric()->isDraft() && Gate::allows('publish', $this->rubric()))
                ->action(function () {
                    try {
                        app(PublishRubric::class)->handle(ActionErrors::actor(), $this->rubric());
                    } catch (ValidationException $exception) {
                        Notification::make()->danger()->title(ActionErrors::summary($exception))->send();

                        return null;
                    }

                    Notification::make()->success()->title(__('rubrics.actions.published'))->send();

                    return redirect($this->listUrl());
                }),
            Action::make('back')
                ->label(__('rubrics.actions.back'))
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(fn (): string => $this->listUrl()),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return RubricFormData::from($this->rubric()->load('criteria'));
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $rubric = $record instanceof Rubric ? $record : throw new LogicException('La página de edición no tiene una rúbrica.');

        try {
            $saved = app(SaveRubric::class)->handle(ActionErrors::actor(), $this->event(), $data, $rubric);
        } catch (ValidationException $exception) {
            throw ActionErrors::forForm($exception);
        }

        $this->newVersion = $saved->id === $rubric->id ? null : $saved->version;

        if ($saved->blind_review) {
            Notification::make()->warning()->title(__('rubrics.blind.saved_warning'))->persistent()->send();
        }

        return $saved;
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return $this->newVersion === null
            ? parent::getSavedNotificationTitle()
            : __('rubrics.actions.saved_new_version', ['version' => $this->newVersion]);
    }

    protected function getRedirectUrl(): string
    {
        return $this->listUrl();
    }

    private function listUrl(): string
    {
        return EventResource::getUrl('rubrics', ['record' => $this->event()]);
    }

    private function rubric(): Rubric
    {
        $record = $this->getRecord();

        return $record instanceof Rubric ? $record : throw new LogicException('La página de edición no tiene una rúbrica.');
    }

    private function event(): Event
    {
        $event = $this->getParentRecord();

        return $event instanceof Event ? $event : throw new LogicException('La página de edición no tiene un evento en la ruta.');
    }
}
