<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events;

use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Resources\Events\RelationManagers\CallsRelationManager;
use App\Filament\Resources\Events\RelationManagers\PrivacyNoticesRelationManager;
use App\Filament\Resources\Events\RelationManagers\SubmissionTypesRelationManager;
use App\Filament\Resources\Events\RelationManagers\TracksRelationManager;
use App\Filament\Resources\Events\Schemas\EventForm;
use App\Filament\Resources\Events\Schemas\EventInfolist;
use App\Filament\Resources\Events\Tables\EventsTable;
use App\Models\Event;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Eventos de la organización (plan 003, H-003-1). La organización es el tenant
 * del panel: la consulta pasa por el scope global de `BelongsToOrganization` y
 * un evento de otra organización responde 404 en su URL. No se ofrece borrar:
 * un evento se archiva (nunca se elimina físicamente).
 */
class EventResource extends Resource
{
    protected static ?string $model = Event::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    public static function getNavigationLabel(): string
    {
        return __('events.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('events.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('events.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return EventForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return EventInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EventsTable::configure($table);
    }

    /** @return array<int, class-string> */
    public static function getRelations(): array
    {
        return [
            CallsRelationManager::class,
            SubmissionTypesRelationManager::class,
            TracksRelationManager::class,
            PrivacyNoticesRelationManager::class,
        ];
    }

    /** @return array<string, mixed> */
    public static function getPages(): array
    {
        return [
            'index' => ListEvents::route('/'),
            'create' => CreateEvent::route('/create'),
            'view' => ViewEvent::route('/{record}'),
            'edit' => EditEvent::route('/{record}/edit'),
        ];
    }
}
