<?php

declare(strict_types=1);

namespace App\Filament\Resources\Forms\Pages;

use App\Actions\Forms\UpdateFormDefinition;
use App\Filament\Resources\Forms\FormDefinitionResource;
use App\Filament\Support\ActionErrors;
use App\Models\FormDefinition;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use LogicException;

class EditFormDefinition extends EditRecord
{
    protected static string $resource = FormDefinitionResource::class;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $form = $record instanceof FormDefinition ? $record : throw new LogicException('La página de edición no tiene un formulario.');

        try {
            return app(UpdateFormDefinition::class)->handle(ActionErrors::actor(), $form, $data);
        } catch (ValidationException $exception) {
            throw ActionErrors::forForm($exception);
        }
    }
}
