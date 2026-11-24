<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Rubrics\RubricResource;
use App\Models\Event;
use App\Models\Rubric;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * Rúbricas del evento (plan 011, F4b): la lista de `RubricResource`, anidada bajo el evento. El evento sale
 * de la ruta, así que un evento de otra organización responde 404; entra quien tiene `rubrics.manage`
 * (`RubricPolicy`). Delgado: las reglas viven en las Actions.
 */
class ManageEventRubrics extends ManageRelatedRecords
{
    protected static string $resource = EventResource::class;

    protected static string $relationship = 'rubrics';

    protected static ?string $relatedResource = RubricResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    public static function getNavigationLabel(): string
    {
        return __('rubrics.navigation');
    }

    /** La sección se llama como su entrada de menú, no «Administrar Rúbricas Evento». */
    public function getTitle(): string
    {
        return __('rubrics.heading');
    }

    /**
     * Ver la pantalla exige `rubrics.manage`. La comprobación de Filament por defecto miraría la Policy del
     * evento y dejaría fuera a quien gestiona rúbricas sin gestionar eventos.
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function canAccess(array $parameters = []): bool
    {
        return Gate::allows('viewAny', Rubric::class);
    }

    /** @return array<int, mixed> */
    protected function getHeaderActions(): array
    {
        return [
            // Una acción simple: `CreateAction` consultaría `RubricPolicy::create` sin el evento.
            Action::make('create')
                ->label(__('rubrics.actions.create'))
                ->icon(Heroicon::OutlinedPlus)
                ->url(fn (): string => RubricResource::getUrl('create', ['event' => $this->eventOfPage()]))
                ->visible(fn (): bool => Gate::allows('create', [Rubric::class, $this->eventOfPage()])),
        ];
    }

    private function eventOfPage(): Event
    {
        $record = $this->getRecord();

        return $record instanceof Event ? $record : throw new LogicException('La página de rúbricas no tiene un evento.');
    }
}
