<?php

declare(strict_types=1);

namespace App\Support\Landings;

use App\Actions\Landings\ResolveLandingBlocks;
use App\Models\Event;
use App\Models\EventLanding;
use App\Models\Organization;
use Illuminate\Support\Facades\Lang;

/**
 * Lo que recibe el editor visual de la landing al montarse en el panel (plan 022, F4): la forma de
 * `LandingEditorProps` (`resources/js/types/landing.ts`). El borrador viaja con todos los idiomas del
 * evento, las imágenes con su URL autenticada del editor y los datos vivos del evento (en el idioma base)
 * para que el lienzo dibuje los bloques que no guardan copia. Nunca incluye la versión publicada ni
 * identificadores de organización.
 */
final class LandingEditorProps
{
    /** @return array<string, mixed> */
    public static function from(Event $event, Organization $organization, EventLanding $landing, ResolveLandingBlocks $resolve): array
    {
        $locales = $event->offeredLocales();

        return [
            'blocks' => LandingBlocks::forEditor($landing->draft_blocks, $locales, LandingImageUrls::forEditor($event, $organization, $landing)),
            'version' => $landing->version,
            'locales' => $locales,
            'defaultLocale' => $event->default_locale,
            // Los mismos textos de `lang/public.php` que el sitio público; el editor añade los suyos (`landing_editor.*`).
            'translations' => Lang::get('public'),
            'preview' => $resolve->preview($event, $organization, $event->default_locale),
            'publicUrl' => route('events.show', ['organization' => $organization->slug, 'event' => $event->slug], false),
            'hasUnpublishedChanges' => $landing->hasUnpublishedChanges(),
        ];
    }
}
