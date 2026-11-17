<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\Pages;

use App\Actions\Events\UpdateEvent;
use App\Filament\Resources\Events\Actions\LifecycleActions;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Support\ActionErrors;
use App\Models\Event;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use LogicException;

class EditEvent extends EditRecord
{
    protected static string $resource = EventResource::class;

    /** @return array<int, mixed> */
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            LifecycleActions::publish(),
            LifecycleActions::archive(),
        ];
    }

    /**
     * Los instantes se guardan en UTC; el formulario los muestra (y los vuelve a
     * recibir) como hora local de la zona horaria del evento (CA-003-4).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $event = $this->event();

        return [
            ...$data,
            'name' => $event->getTranslations('name'),
            'description' => $event->getTranslations('description'),
            'starts_at' => $event->starts_at->copy()->setTimezone($event->timezone)->format('Y-m-d H:i'),
            'ends_at' => $event->ends_at->copy()->setTimezone($event->timezone)->format('Y-m-d H:i'),
        ];
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(UpdateEvent::class)->handle(
                ActionErrors::actor(),
                $this->event(),
                $data,
                confirmSlugChange: (bool) ($data['confirm_slug_change'] ?? false),
            );
        } catch (ValidationException $exception) {
            throw ActionErrors::forForm($exception);
        }
    }

    private function event(): Event
    {
        $record = $this->getRecord();

        return $record instanceof Event ? $record : throw new LogicException('La página de edición no tiene un evento.');
    }
}
