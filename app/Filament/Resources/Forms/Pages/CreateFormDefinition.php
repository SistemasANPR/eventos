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
use LogicException;

class CreateFormDefinition extends CreateRecord
{
    protected static string $resource = FormDefinitionResource::class;

    /** Crear y cancelar arriba: el formulario es largo y así no hay que bajar hasta el final. */
    protected function getHeaderActions(): array
    {
        return [
            $this->getCreateFormAction()->formId('form'),
            $this->getCancelFormAction()->formId('form'),
        ];
    }

    /** Los botones van en la cabecera, no al final del formulario. */
    protected function getFormActions(): array
    {
        return [];
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        // El evento sale de la ruta, ya acotado por la organización (un evento de otra responde 404); no viaja en el formulario.
        $event = $this->getParentRecord();

        if (! $event instanceof Event) {
            throw new LogicException('La página de alta no tiene un evento en la ruta.');
        }

        try {
            return app(CreateFormDefinitionAction::class)->handle(ActionErrors::actor(), $event, $data);
        } catch (ValidationException $exception) {
            throw ActionErrors::forForm($exception);
        }
    }
}
