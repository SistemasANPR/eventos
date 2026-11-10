<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Http\Requests\Events\StoreEventRequest;
use App\Models\Event;
use Filament\Forms\Components\TextInput;

/**
 * Campos del nombre de un tipo de propuesta (plan 004, DA-7): uno por idioma del
 * evento, con el idioma base obligatorio. Los comparten la sección de tipos del
 * evento y el acceso «crear tipo» del selector de la convocatoria.
 */
final class SubmissionTypeFields
{
    /** @return list<TextInput> */
    public static function names(Event $event): array
    {
        $fields = [];

        foreach (StoreEventRequest::LOCALES as $locale) {
            $fields[] = TextInput::make("name.{$locale}")
                ->label(__("calls.fields.type_name_{$locale}"))
                ->maxLength(100)
                ->required($locale === $event->default_locale)
                ->visible(in_array($locale, $event->locales, true));
        }

        return $fields;
    }
}
