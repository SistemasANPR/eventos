<?php

declare(strict_types=1);

namespace App\Support\Program;

use App\Models\Event;
use App\Models\Speaker;
use App\Support\Html\RichTextSanitizer;
use App\Support\Tenancy\CurrentOrganization;

/**
 * Lo que el sitio público sabe de un ponente (plan 029, DA-6, §4.3): una lista CERRADA de campos,
 * armada a mano. Nunca el correo, el país, los identificadores internos ni la ruta de la fotografía;
 * nunca `toArray()` de un modelo. La biografía se vuelve a sanear al salir y solo viajan las redes
 * `https`. Requiere organización en contexto (de ahí sale su `slug` para las direcciones).
 */
final class PublicSpeakerData
{
    /**
     * Ficha resumida (tarjetas y listas).
     *
     * @return array{id: string, name: string, company: string|null, jobTitle: string|null, tagline: string|null, photoUrl: string|null, url: string}
     */
    public static function summary(Speaker $speaker, Event $event): array
    {
        $organization = CurrentOrganization::get()->slug;

        return [
            'id' => $speaker->id,
            'name' => self::name($speaker),
            'company' => $speaker->company,
            'jobTitle' => $speaker->job_title,
            'tagline' => $speaker->tagline,
            'photoUrl' => $speaker->photo_path === null
                ? null
                : route('public.speakers.photo', ['organization' => $organization, 'event' => $event->slug, 'speaker' => $speaker->id], false),
            // La página de la ficha llega en F3 (`events.speakers.show`); la ruta se construye igual que las demás.
            'url' => "/{$organization}/events/{$event->slug}/speakers/{$speaker->id}",
        ];
    }

    /**
     * Ficha completa. Requiere cargadas las categorías del ponente.
     *
     * @return array{id: string, name: string, company: string|null, jobTitle: string|null, tagline: string|null, photoUrl: string|null, url: string, bioHtml: string|null, categories: list<string>, socialLinks: list<array{network: string, url: string}>}
     */
    public static function detail(Speaker $speaker, Event $event): array
    {
        $bio = $speaker->bio === null ? '' : RichTextSanitizer::clean($speaker->bio);

        return [
            ...self::summary($speaker, $event),
            'bioHtml' => $bio === '' ? null : $bio,
            'categories' => $speaker->categories->pluck('name')->values()->all(),
            'socialLinks' => self::httpsLinks($speaker->social_links),
        ];
    }

    private static function name(Speaker $speaker): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $speaker->full_name));
    }

    /**
     * Solo las redes con una dirección `https` válida; las demás se omiten en silencio (`javascript:`,
     * `data:`, `http:`, direcciones rotas).
     *
     * @param  list<array{network: string, url: string}>  $links
     * @return list<array{network: string, url: string}>
     */
    private static function httpsLinks(array $links): array
    {
        $safe = [];

        foreach ($links as $link) {
            $network = $link['network'] ?? null;
            $url = is_string($link['url'] ?? null) ? trim($link['url']) : '';

            if (! is_string($network) || filter_var($url, FILTER_VALIDATE_URL) === false) {
                continue;
            }

            if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https') {
                $safe[] = ['network' => $network, 'url' => $url];
            }
        }

        return $safe;
    }
}
