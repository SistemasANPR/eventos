<?php

declare(strict_types=1);

namespace App\Filament\Resources\Submissions\Pages;

use App\Filament\Resources\Submissions\SubmissionResource;
use Filament\Resources\Pages\ListRecords;

/** Lista de propuestas del evento de la ruta (plan 009, H-009-1). */
class ListSubmissions extends ListRecords
{
    protected static string $resource = SubmissionResource::class;
}
