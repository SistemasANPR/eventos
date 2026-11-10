<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\Pages;

use App\Actions\Events\CreateEvent as CreateEventAction;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Support\ActionErrors;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateEvent extends CreateRecord
{
    protected static string $resource = EventResource::class;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(CreateEventAction::class)->handle(ActionErrors::actor(), CurrentOrganization::get(), $data);
        } catch (ValidationException $exception) {
            throw ActionErrors::forForm($exception);
        }
    }
}
