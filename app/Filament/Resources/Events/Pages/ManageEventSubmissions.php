<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Submissions\SubmissionResource;
use App\Filament\Resources\Submissions\Tables\SubmissionsTable;
use App\Models\Event;
use App\Models\Submission;
use BackedEnum;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Propuestas del evento (plan 020, F2; recurso del plan 009): la lista que antes se abría solo por URL
 * pasa a ser una sección del evento. La tabla viene de `SubmissionResource`, anidado bajo el evento;
 * el evento sale de la ruta, así que un evento de otra organización responde 404. Quién la ve lo
 * decide `SubmissionPolicy`. Solo lectura: sin acciones de cabecera.
 */
class ManageEventSubmissions extends ManageRelatedRecords
{
    protected static string $resource = EventResource::class;

    protected static string $relationship = 'submissions';

    protected static ?string $relatedResource = SubmissionResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    public static function getNavigationLabel(): string
    {
        return __('submission_panel.navigation');
    }

    /** La sección se llama como su entrada de menú, no «Administrar Propuestas Evento». */
    public function getTitle(): string
    {
        return __('submission_panel.navigation');
    }

    /**
     * La página de relación arma su consulta desde `$evento->submissions()` y no pasa por
     * `SubmissionResource::getEloquentQuery()`: sin esto volverían los invitados (plan 018) y se
     * perdería la carga anticipada. Se aplican las reglas de la lista a la relación del evento
     * (Filament exige una relación aquí, no una consulta).
     *
     * @return HasMany<Submission, Event>
     */
    public function getRelationship(): HasMany
    {
        $event = $this->getOwnerRecord();

        if (! $event instanceof Event) {
            throw new LogicException('La página de propuestas no tiene un evento.');
        }

        return SubmissionResource::scopeToList($event->submissions());
    }

    /**
     * Un filtro con un valor que no es del evento actual se limpia y produce el mismo error de
     * validación genérico, sea ajeno, inexistente o mal formado (plan 009, CA-009-4).
     */
    public function updatedTableFilters(): void
    {
        $event = $this->getOwnerRecord();

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
