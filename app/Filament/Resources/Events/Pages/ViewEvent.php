<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\Actions\LifecycleActions;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Tables\EventsTable;
use App\Filament\Resources\Submissions\SubmissionResource;
use App\Models\Event;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/** Panel del evento: el resumen del evento elegido, con sus acciones. */
class ViewEvent extends ViewRecord
{
    protected static string $resource = EventResource::class;

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
            Action::make('submissions')
                ->label(__('submission_panel.navigation'))
                ->icon(Heroicon::OutlinedInboxArrowDown)
                ->color('gray')
                ->url(fn (): string => SubmissionResource::getUrl('index', ['event' => $this->getRecord()]))
                ->visible(fn (): bool => SubmissionResource::canAccess()),
            EditAction::make(),
            LifecycleActions::publish(),
            LifecycleActions::archive(),
        ];
    }
}
