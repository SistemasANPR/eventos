<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Events;

use App\Enums\EventStatus;
use App\Filament\Platform\Resources\Events\Pages\ListEvents;
use App\Filament\Platform\Support\ReadOnlyResource;
use App\Filament\Resources\Events\Tables\EventsTable;
use App\Models\Event;
use App\Models\Organization;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Eventos de todas las organizaciones, de solo lectura (plan 016, H-016-4). Solo
 * metadatos: organización, nombre, estado, fechas e idiomas. La consulta salta
 * el filtro de organización con `withoutOrganizationScope()`, que audita cada
 * uso. Editar, publicar o archivar es del panel de la organización.
 */
class EventResource extends ReadOnlyResource
{
    protected static ?string $model = Event::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 30;

    public static function getNavigationLabel(): string
    {
        return __('platform.events.plural');
    }

    public static function getModelLabel(): string
    {
        return __('platform.events.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('platform.events.plural');
    }

    /** @return Builder<Event> */
    public static function getEloquentQuery(): Builder
    {
        return Event::query()->withoutOrganizationScope()->with('organization');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('organization.name')->label(__('platform.events.columns.organization'))->weight('medium'),
                TextColumn::make('name')
                    ->label(__('platform.events.columns.name'))
                    ->state(fn (Event $record): string => EventsTable::displayName($record)),
                TextColumn::make('status')->label(__('platform.events.columns.status'))->badge(),
                TextColumn::make('starts_at')
                    ->label(__('platform.events.columns.dates'))
                    ->state(fn (Event $record): string => EventsTable::displayDates($record)),
                TextColumn::make('locales')
                    ->label(__('platform.events.columns.locales'))
                    ->state(fn (Event $record): string => strtoupper(implode(' · ', $record->locales))),
            ])
            ->filters([
                SelectFilter::make('organization_id')
                    ->label(__('platform.events.columns.organization'))
                    ->options(fn (): array => Organization::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('status')
                    ->label(__('platform.events.columns.status'))
                    ->options(EventStatus::class),
            ])
            ->defaultSort('starts_at', 'desc')
            ->paginationPageOptions([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading(__('platform.events.empty'));
    }

    /** @return array<string, mixed> */
    public static function getPages(): array
    {
        return ['index' => ListEvents::route('/')];
    }
}
