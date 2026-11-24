<?php

declare(strict_types=1);

namespace App\Support\Reviews;

use App\Models\Event;
use App\Models\FormDefinition;

/**
 * Etiquetas de los campos de los formularios del evento que el organizador marcó con `identifies_author`
 * (plan 011, CA-011-21): el modo ciego solo oculta esos, así que la pantalla de rúbricas se los muestra
 * junto a la advertencia. Exige la organización en contexto. Solo lectura; el filtrado real es del modo ciego (F5).
 */
final class AuthorIdentifyingFields
{
    /** @return list<string> etiquetas sin repetir, en el idioma base del evento */
    public static function labelsFor(Event $event): array
    {
        $labels = [];

        FormDefinition::query()->where('event_id', $event->id)->orderBy('created_at')->get()
            ->each(function (FormDefinition $form) use ($event, &$labels): void {
                foreach ($form->fields as $field) {
                    if (($field['identifiesAuthor'] ?? false) !== true) {
                        continue;
                    }

                    $label = self::label($field['label'] ?? null, $event->default_locale);
                    $labels[$label !== '' ? $label : (string) $field['key']] = true;
                }
            });

        return array_map(strval(...), array_keys($labels));
    }

    private static function label(mixed $texts, string $locale): string
    {
        if (! is_array($texts)) {
            return '';
        }

        $text = $texts[$locale] ?? $texts['es'] ?? reset($texts);

        return is_string($text) ? $text : '';
    }
}
