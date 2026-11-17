<?php

declare(strict_types=1);

namespace App\Filament\Resources\Forms\Pages;

use App\Actions\Forms\CreateFormDefinition as CreateFormDefinitionAction;
use App\Filament\Resources\Forms\FormDefinitionResource;
use App\Filament\Support\ActionErrors;
use App\Models\Event;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateFormDefinition extends CreateRecord
{
    protected static string $resource = FormDefinitionResource::class;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        // `findOrFail` pasa por el scope de la organización: un evento de otra responde 404.
        $event = Event::query()->findOrFail($data['event_id']);

        try {
            return app(CreateFormDefinitionAction::class)->handle(ActionErrors::actor(), $event, $data);
        } catch (ValidationException $exception) {
            throw ActionErrors::forForm($exception);
        }
    }
}
