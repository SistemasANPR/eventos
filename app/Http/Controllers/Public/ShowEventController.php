<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Actions\Landings\ResolveLandingBlocks;
use App\Models\Event;
use App\Models\EventLanding;
use App\Models\Organization;
use App\Models\PrivacyNotice;
use App\Support\Events\PublicEventData;
use App\Support\Landings\LandingImageUrls;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Página pública de un evento publicado: `/{organization:slug}/events/{event:slug}`.
 * La organización queda en contexto por `SetOrganizationFromRoute` antes del
 * binding; el evento se resuelve acotado a ella (otra organización responde 404).
 * Borrador o archivado responden 404, igual que uno inexistente.
 */
final class ShowEventController
{
    public function __invoke(Request $request, Organization $organization, Event $event, ResolveLandingBlocks $resolve): Response
    {
        abort_unless($event->isPublished(), 404);

        $locale = PublicEventData::resolveLocale($request, $event);
        app()->setLocale($locale);

        return Inertia::render('Events/Show', [
            'event' => PublicEventData::from($event, $organization, $locale),
            // La landing publicada del evento, con sus bloques ya resueltos (plan 022, CA-022-21 y 27); sin ella, `null`
            // y la página muestra la plantilla fija de siempre (CA-022-22). Nunca viaja el borrador.
            'landing' => $this->landing($event, $organization, $locale, $resolve),
            'organization' => ['name' => $organization->name, 'slug' => $organization->slug],
            // Solo se enlaza si hay una versión PUBLICADA (CA-003-19); un borrador no cuenta.
            'privacyUrl' => PrivacyNotice::currentPublished($event) === null
                ? null
                : route('events.privacy', ['organization' => $organization->slug, 'event' => $event->slug], false),
        ]);
    }

    /** @return array{blocks: list<array<string, mixed>>, meta: array{title: string, description: ?string, imageUrl: ?string}}|null */
    private function landing(Event $event, Organization $organization, string $locale, ResolveLandingBlocks $resolve): ?array
    {
        $landing = EventLanding::currentPublished($event);

        if ($landing === null) {
            return null;
        }

        $blocks = $landing->published_blocks ?? [];
        $imageUrl = LandingImageUrls::forPublic($event, $organization, $landing);
        $meta = $resolve->meta($event, $blocks, $locale, $imageUrl);

        return [
            'blocks' => $resolve->handle($event, $organization, $blocks, $locale, $imageUrl),
            // `og:image` necesita una dirección absoluta.
            'meta' => [...$meta, 'imageUrl' => $meta['imageUrl'] === null ? null : url($meta['imageUrl'])],
        ];
    }
}
