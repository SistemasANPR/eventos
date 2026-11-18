<?php

declare(strict_types=1);

namespace App\Support\Submissions;

use App\Actions\Submissions\VerifyTurnstile;
use App\Models\Event;
use App\Models\Organization;

/**
 * Props de la página «pedir mi enlace de acceso» (plan 006, H-006-3): solo el evento, el nombre de la
 * organización y los plazos. Sin datos de propuestas ni del correo escrito. La comparten la vista GET y
 * la respuesta 429.
 */
final class AccessLinkPageData
{
    /**
     * @return array{event: array{name: string, locale: string, locales: list<string>}, organization: array{name: string, slug: string}, turnstileSiteKey: string, linkRequested: bool, tooManyRequests: bool, maxLinks: int, expiresInMinutes: int, eventUrl: string}
     */
    public static function from(Organization $organization, Event $event, string $locale, bool $linkRequested = false, bool $tooManyRequests = false): array
    {
        return [
            'event' => ['name' => $event->getTranslation('name', $locale), 'locale' => $locale, 'locales' => $event->offeredLocales()],
            'organization' => ['name' => $organization->name, 'slug' => $organization->slug],
            'turnstileSiteKey' => VerifyTurnstile::siteKey(),
            'linkRequested' => $linkRequested,
            'tooManyRequests' => $tooManyRequests,
            'maxLinks' => (int) config('submissions.author.max_links_per_email'),
            'expiresInMinutes' => (int) config('submissions.author.access_link_minutes'),
            'eventUrl' => route('events.show', ['organization' => $organization->slug, 'event' => $event->slug], false)."?lang={$locale}",
        ];
    }
}
