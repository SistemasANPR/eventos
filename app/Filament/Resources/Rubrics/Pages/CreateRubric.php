<?php

declare(strict_types=1);

namespace App\Filament\Resources\Rubrics\Pages;

use App\Actions\Reviews\SaveRubric;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Rubrics\RubricResource;
use App\Filament\Resources\Rubrics\Schemas\RubricFormData;
use App\Filament\Support\ActionErrors;
use App\Models\Event;
use App\Models\Rubric;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Alta de una rúbrica en borrador (plan 011, F4b). Con `?from={rúbrica}` parte de una copia de esa rúbrica
 * del mismo evento («Nueva versión»): al guardar, `SaveRubric` le asigna la versión siguiente.
 */
class CreateRubric extends CreateRecord
{
    protected static string $resource = RubricResource::class;

    public function getTitle(): string
    {
        return __('rubrics.create_title');
    }

    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $from = request()->query('from');

        // La consulta se acota por el evento de la ruta y por la organización: una rúbrica ajena responde 404.
        $source = is_string($from) && $from !== ''
            ? Rubric::query()->where('event_id', $this->event()->id)->with('criteria')->findOrFail($from)
            : null;

        $this->form->fill($source === null ? [] : RubricFormData::from($source));

        $this->callHook('afterFill');
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(SaveRubric::class)->handle(ActionErrors::actor(), $this->event(), $data);
        } catch (ValidationException $exception) {
            throw ActionErrors::forForm($exception);
        }
    }

    protected function afterCreate(): void
    {
        $rubric = $this->getRecord();

        // CA-011-21: quien activa la revisión ciega ve la advertencia también al guardar.
        if ($rubric instanceof Rubric && $rubric->blind_review) {
            Notification::make()->warning()->title(__('rubrics.blind.saved_warning'))->persistent()->send();
        }
    }

    protected function getRedirectUrl(): string
    {
        return EventResource::getUrl('rubrics', ['record' => $this->event()]);
    }

    private function event(): Event
    {
        $event = $this->getParentRecord();

        return $event instanceof Event ? $event : throw new LogicException('La página de alta no tiene un evento en la ruta.');
    }
}
