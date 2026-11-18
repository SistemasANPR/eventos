<?php

declare(strict_types=1);

namespace App\Filament\Support;

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
        /** @var list<TextInput> */
        return LocalizedFields::for($event, static fn (string $locale, bool $isDefault): TextInput => TextInput::make("name.{$locale}")
            ->label(__("calls.fields.type_name_{$locale}"))
            ->maxLength(100)
            ->required($isDefault));
    }
}
