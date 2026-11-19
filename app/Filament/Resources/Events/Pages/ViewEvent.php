<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\Actions\LandingActions;
use App\Filament\Resources\Events\Actions\LifecycleActions;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Tables\EventsTable;
use App\Models\Event;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

/** Panel del evento: el resumen del evento elegido, con sus acciones. */
class ViewEvent extends ViewRecord
{
    protected static string $resource = EventResource::class;

    /** Etiqueta de la sección en la sub-navegación del evento (plan 020). */
    public static function getNavigationLabel(): string
    {
        return __('events.sub_navigation.summary');
    }

    public function getTitle(): string
    {
        $event = $this->getRecord();

        return $event instanceof Event ? EventsTable::displayName($event) : parent::getTitle();
    }

    /** Solo las pestañas del evento: el resumen ya se ve en su tarjeta de la lista. */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getRelationManagersContentComponent(),
        ]);
    }

    /** @return array<int, mixed> */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            LandingActions::design(),
            LifecycleActions::publish(),
            LifecycleActions::archive(),
        ];
    }
}
