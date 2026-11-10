<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Http\Requests\Events\StoreEventRequest;
use App\Models\Event;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

/**
 * Campos de un eje temático (plan 004, H-004-2): nombre y descripción por
 * idioma del evento, con el nombre obligatorio solo en el idioma base.
 */
final class TrackFields
{
    /** @return list<TextInput|Textarea> */
    public static function inputs(Event $event): array
    {
        $fields = [];

        foreach (StoreEventRequest::LOCALES as $locale) {
            $visible = in_array($locale, $event->locales, true);

            $fields[] = TextInput::make("name.{$locale}")
                ->label(__("calls.fields.track_name_{$locale}"))
                ->maxLength(100)
                ->required($locale === $event->default_locale)
                ->visible($visible);
            $fields[] = Textarea::make("description.{$locale}")
                ->label(__("calls.fields.track_description_{$locale}"))
                ->maxLength(1000)
                ->rows(3)
                ->visible($visible);
        }

        return $fields;
    }
}
