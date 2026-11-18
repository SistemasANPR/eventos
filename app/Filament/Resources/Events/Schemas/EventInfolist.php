<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\Schemas;

use App\Enums\EventStatus;
use App\Filament\Resources\Events\Tables\EventsTable;
use App\Models\Event;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** Resumen de solo lectura de un evento: el «panel del evento». */
final class EventInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->schema([
                    TextEntry::make('name')
                        ->label(__('events.table.name'))
                        ->state(fn (Event $record): string => EventsTable::displayName($record)),
                    TextEntry::make('status')
                        ->label(__('events.fields.status'))
                        ->badge(),
                    TextEntry::make('starts_at')
                        ->label(__('events.table.starts_at'))
                        ->state(fn (Event $record): string => EventsTable::displayDates($record)),
                    TextEntry::make('timezone')
                        ->label(__('events.fields.timezone')),
                    TextEntry::make('venue_name')
                        ->label(__('events.sections.venue'))
                        ->state(fn (Event $record): string => implode(', ', array_filter([$record->venue_name, $record->city])))
                        ->placeholder('—'),
                    TextEntry::make('locales')
                        ->label(__('events.fields.locales'))
                        ->state(fn (Event $record): string => strtoupper(implode(' · ', $record->locales))),
                    TextEntry::make('public_url')
                        ->label(__('events.fields.public_url'))
                        ->state(fn (Event $record): string => route('events.show', [
                            'organization' => CurrentOrganization::get()->slug,
                            'event' => $record->slug,
                        ]))
                        ->copyable()
                        ->visible(fn (Event $record): bool => $record->status === EventStatus::Published)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
