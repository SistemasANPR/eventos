<?php

declare(strict_types=1);

namespace App\Support\Landings;

use App\Models\Event;
use App\Models\EventLanding;
use App\Models\LandingImage;
use App\Models\Organization;
use Closure;

/**
 * Construye las URLs de las imágenes de una landing (plan 022, DA-7): una función por id que devuelve la
 * URL de una imagen que existe y no está borrada, o `null`. El sitio público usa la ruta pública (que solo
 * sirve lo publicado); el editor, la ruta autenticada del panel (que también sirve el borrador). La
 * organización sale del contexto de la petición y las URLs son relativas.
 */
final class LandingImageUrls
{
    /** @return Closure(string): ?string */
    public static function forPublic(Event $event, Organization $organization, EventLanding $landing): Closure
    {
        $existing = self::existing($landing);

        return static fn (string $id): ?string => isset($existing[$id])
            ? route('public.landings.image', ['organization' => $organization->slug, 'event' => $event->slug, 'image' => $id], false)
            : null;
    }

    /** @return Closure(string): ?string */
    public static function forEditor(Event $event, Organization $organization, EventLanding $landing): Closure
    {
        $existing = self::existing($landing);

        return static fn (string $id): ?string => isset($existing[$id])
            ? route('landing-editor.images.show', ['organization' => $organization->slug, 'event' => $event->id, 'image' => $id], false)
            : null;
    }

    /** @return array<string, true> */
    private static function existing(EventLanding $landing): array
    {
        $ids = LandingImage::query()->where('event_landing_id', $landing->id)->pluck('id')->all();

        return array_fill_keys($ids, true);
    }
}
