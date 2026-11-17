<?php

declare(strict_types=1);

namespace App\Filament\Resources\Submissions\Pages;

use App\Filament\Resources\Submissions\SubmissionResource;
use App\Filament\Resources\Submissions\Tables\SubmissionsTable;
use App\Models\Event;
use Filament\Resources\Pages\ListRecords;

/** Lista de propuestas del evento de la ruta (plan 009, H-009-1). */
class ListSubmissions extends ListRecords
{
    protected static string $resource = SubmissionResource::class;

    /**
     * Un filtro con un valor que no es del evento actual se limpia y produce el mismo error de
     * validación genérico, sea ajeno, inexistente o mal formado (CA-009-4).
     */
    public function updatedTableFilters(): void
    {
        $event = $this->getParentRecord();

        if ($event instanceof Event) {
            $invalid = SubmissionsTable::invalidSelectFilters($this->tableFilters ?? [], $event);

            foreach ($invalid as $name) {
                $this->tableFilters[$name]['value'] = null;
            }

            if ($invalid !== []) {
                throw SubmissionsTable::invalidFilter();
            }
        }

        parent::updatedTableFilters();
    }
}
