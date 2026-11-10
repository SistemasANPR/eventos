<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\Schemas;

use App\Enums\EventType;
use App\Http\Requests\Events\StoreEventRequest;
use App\Models\Event;
use DateTimeZone;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Formulario de un evento. Es solo presentación: la validación y las reglas
 * de negocio viven en las Actions (`StoreEventRequest`), no aquí.
 */
final class EventForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('events.sections.general'))
                ->schema([
                    ...self::translatedTexts(),
                    TextInput::make('slug')
                        ->label(__('events.fields.slug'))
                        ->helperText(__('events.fields.slug_help'))
                        ->required()
                        ->maxLength(100),
                    Toggle::make('confirm_slug_change')
                        ->label(__('events.fields.confirm_slug_change'))
                        ->helperText(__('events.fields.confirm_slug_change_help'))
                        ->visible(fn (?Event $record): bool => $record?->isPublished() === true)
                        ->dehydrated(),
                    Select::make('type')
                        ->label(__('events.fields.type'))
                        ->options(EventType::class)
                        ->default(EventType::Congress)
                        ->required(),
                ]),
            Section::make(__('events.sections.dates'))
                ->description(__('events.fields.dates_help'))
                ->columns(2)
                ->schema([
                    DateTimePicker::make('starts_at')
                        ->label(__('events.fields.starts_at'))
                        ->seconds(false)
                        ->format('Y-m-d H:i')
                        ->required(),
                    DateTimePicker::make('ends_at')
                        ->label(__('events.fields.ends_at'))
                        ->seconds(false)
                        ->format('Y-m-d H:i')
                        ->required(),
                    Select::make('timezone')
                        ->label(__('events.fields.timezone'))
                        ->options(self::timezones())
                        ->searchable()
                        ->default('America/Mexico_City')
                        ->required(),
                ]),
            Section::make(__('events.sections.venue'))
                ->columns(2)
                ->schema([
                    TextInput::make('venue_name')->label(__('events.fields.venue_name'))->maxLength(255),
                    TextInput::make('city')->label(__('events.fields.city'))->maxLength(255),
                    TextInput::make('venue_address')->label(__('events.fields.venue_address'))->maxLength(255)->columnSpanFull(),
                ]),
            Section::make(__('events.sections.languages'))
                ->columns(2)
                ->schema([
                    CheckboxList::make('locales')
                        ->label(__('events.fields.locales'))
                        ->options(self::localeOptions())
                        ->default(['es'])
                        ->live()
                        ->required(),
                    Select::make('default_locale')
                        ->label(__('events.fields.default_locale'))
                        ->options(fn (Get $get): array => array_intersect_key(self::localeOptions(), array_flip((array) $get('locales'))))
                        ->default('es')
                        ->required(),
                    Select::make('currency')
                        ->label(__('events.fields.currency'))
                        ->options(array_combine(StoreEventRequest::CURRENCIES, StoreEventRequest::CURRENCIES))
                        ->default('MXN')
                        ->required(),
                ]),
        ]);
    }

    /**
     * Un nombre y una descripción por cada idioma, visibles solo para los idiomas
     * que el evento ofrece.
     *
     * @return list<TextInput|Textarea>
     */
    private static function translatedTexts(): array
    {
        $fields = [];

        foreach (StoreEventRequest::LOCALES as $locale) {
            $isOffered = static fn (Get $get): bool => in_array($locale, (array) $get('locales'), true);

            $fields[] = TextInput::make("name.{$locale}")
                ->label(__("events.fields.name_{$locale}"))
                ->maxLength(255)
                ->visible($isOffered);
            $fields[] = Textarea::make("description.{$locale}")
                ->label(__("events.fields.description_{$locale}"))
                ->maxLength(5000)
                ->rows(3)
                ->visible($isOffered);
        }

        return $fields;
    }

    /** @return array<string, string> */
    private static function localeOptions(): array
    {
        return ['es' => 'Español', 'en' => 'English', 'pt' => 'Português'];
    }

    /** @return array<string, string> */
    private static function timezones(): array
    {
        $identifiers = DateTimeZone::listIdentifiers();

        return array_combine($identifiers, $identifiers);
    }
}
