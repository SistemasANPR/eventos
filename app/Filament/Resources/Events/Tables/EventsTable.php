<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\Tables;

use App\Filament\Resources\Events\Actions\LifecycleActions;
use App\Filament\Resources\Events\EventResource;
use App\Models\Event;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Lista de eventos de la organización. Es también el SELECTOR de evento
 * (plan 003, DA-8): sin evento elegido, las pantallas por evento piden elegir
 * uno aquí; nunca toman uno al azar.
 */
final class EventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('events.table.name'))
                    ->state(fn (Event $record): string => self::displayName($record))
                    ->weight('medium'),
                TextColumn::make('status')
                    ->label(__('events.fields.status'))
                    ->badge(),
                TextColumn::make('type')
                    ->label(__('events.fields.type'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('starts_at')
                    ->label(__('events.table.starts_at'))
                    ->state(fn (Event $record): string => self::displayDates($record))
                    ->sortable(),
            ])
            ->defaultSort('starts_at', 'desc')
            ->recordUrl(fn (Event $record): string => EventResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    LifecycleActions::publish(),
                    LifecycleActions::archive(),
                ]),
            ])
            ->emptyStateHeading(__('events.table.empty_heading'))
            ->emptyStateDescription(__('events.table.empty_description'))
            ->emptyStateActions([CreateAction::make()]);
    }

    /** Nombre en el idioma por defecto del evento, con respaldo a cualquier otro. */
    public static function displayName(Event $event): string
    {
        return (string) $event->getTranslation('name', $event->default_locale, true);
    }

    /** Fechas en la zona horaria DEL EVENTO (se guardan en UTC), p. ej. «10 mar 2027, 9:00 – 12 mar 2027, 17:00». */
    public static function displayDates(Event $event): string
    {
        $format = 'j M Y, H:i';

        return $event->starts_at->copy()->setTimezone($event->timezone)->locale(app()->getLocale())->translatedFormat($format)
            .' – '
            .$event->ends_at->copy()->setTimezone($event->timezone)->locale(app()->getLocale())->translatedFormat($format);
    }
}
