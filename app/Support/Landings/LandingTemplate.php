<?php

declare(strict_types=1);

namespace App\Support\Landings;

use App\Enums\LandingBlockType;
use App\Models\Event;
use Illuminate\Support\Str;
use LogicException;

/**
 * Plantilla predeterminada de la landing de un evento (plan 022, DA-3, DA-6): portada, datos del
 * evento, convocatorias y ejes. La portada toma el nombre del evento en cada uno de sus idiomas; los
 * demás bloques no guardan copia de datos. Cada llamada genera identificadores nuevos.
 */
final class LandingTemplate
{
    /** @return list<array<string, mixed>> */
    public static function blocks(Event $event): array
    {
        $title = [];

        foreach ($event->offeredLocales() as $locale) {
            $name = (string) $event->getTranslation('name', $locale, false);

            if ($name !== '') {
                $title[$locale] = Str::limit($name, 160, '');
            }
        }

        // Sin un nombre en el idioma base no hay título válido: se usa el que tenga el evento.
        $title[$event->default_locale] ??= Str::limit((string) $event->getTranslation('name', $event->default_locale, true), 160, '');

        return [
            self::block($event, LandingBlockType::Hero, ['title' => $title]),
            self::block($event, LandingBlockType::EventInfo, []),
            self::block($event, LandingBlockType::Calls, []),
            self::block($event, LandingBlockType::Tracks, []),
        ];
    }

    /**
     * Cada bloque pasa por las reglas de su tipo: la plantilla nunca produce algo que el editor rechazaría.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private static function block(Event $event, LandingBlockType $type, array $content): array
    {
        $checked = LandingBlockRules::check($type, $content, $event->offeredLocales(), $event->default_locale);

        if ($checked['errors'] !== []) {
            throw new LogicException('La plantilla de la landing no cumple las reglas de «'.$type->value.'»: '.json_encode($checked['errors']));
        }

        return ['id' => (string) Str::uuid(), 'type' => $type->value, ...$checked['content']];
    }
}
