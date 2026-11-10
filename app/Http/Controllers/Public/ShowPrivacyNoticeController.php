<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Models\Event;
use App\Models\Organization;
use App\Models\PrivacyNotice;
use App\Support\Events\PublicEventData;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Aviso de privacidad vigente de un evento publicado:
 * `/{organization:slug}/events/{event:slug}/privacy`. Responde 404 si el evento
 * no está publicado o no tiene ninguna versión publicada (un borrador no vale).
 *
 * El texto llega ya sanitizado desde el servidor. Si falta el idioma pedido se
 * muestra el idioma base del evento y la página lo indica.
 */
final class ShowPrivacyNoticeController
{
    public function __invoke(Request $request, Organization $organization, Event $event): Response
    {
        abort_unless($event->isPublished(), 404);

        $notice = PrivacyNotice::currentPublished($event);

        abort_if($notice === null, 404);

        $locale = PublicEventData::resolveLocale($request, $event);
        app()->setLocale($locale);

        [$body, $noticeLocale] = $this->textFor($notice, $locale, $event->default_locale);

        return Inertia::render('Events/Privacy', [
            'event' => [
                'name' => $event->getTranslation('name', $locale),
                'locale' => $locale,
                'locales' => $event->locales,
            ],
            'organization' => ['name' => $organization->name, 'slug' => $organization->slug],
            'notice' => [
                'version' => $notice->version,
                'publishedAt' => $notice->published_at?->utc()->format('Y-m-d\TH:i:s\Z'),
                'body' => $body,
                'locale' => $noticeLocale,
            ],
            'eventUrl' => route('events.show', ['organization' => $organization->slug, 'event' => $event->slug], false)."?lang={$locale}",
        ]);
    }

    /**
     * @return array{0: string, 1: string} texto y el idioma real en que está
     */
    private function textFor(PrivacyNotice $notice, string $locale, string $defaultLocale): array
    {
        $text = trim((string) $notice->getTranslation('body', $locale, false));

        if ($text !== '') {
            return [$text, $locale];
        }

        return [(string) $notice->getTranslation('body', $defaultLocale, false), $defaultLocale];
    }
}
