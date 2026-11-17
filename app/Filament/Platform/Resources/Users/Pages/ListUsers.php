<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Users\Pages;

use App\Filament\Platform\Resources\Users\UserResource;
use Filament\Resources\Pages\ListRecords;

/** Lista de usuarios de todas las organizaciones (solo lectura). */
class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;
}
