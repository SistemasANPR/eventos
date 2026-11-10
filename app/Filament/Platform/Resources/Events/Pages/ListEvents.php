<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Events\Pages;

use App\Filament\Platform\Resources\Events\EventResource;
use Filament\Resources\Pages\ListRecords;

/** Lista de eventos de todas las organizaciones (solo lectura). */
class ListEvents extends ListRecords
{
    protected static string $resource = EventResource::class;
}
