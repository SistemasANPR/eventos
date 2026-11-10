<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Users\Pages;

use App\Filament\Platform\Resources\Users\UserResource;
use Filament\Resources\Pages\ViewRecord;

/** Detalle de un usuario: datos de la cuenta y sus membresías (solo lectura). */
class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;
}
