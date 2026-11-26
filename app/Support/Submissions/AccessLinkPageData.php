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
 *
 * `event.locales` son los idiomas que ofrece el evento (`Event::offeredLocales()`): con uno solo, la página
 * no muestra selector de idioma (plan 034, CA-034-21). `backUrl` es el destino de «Regresar»
 * (`AccessLinkBackUrl`, plan 034, DA-7).
 */
final class AccessLinkPageData
{
    /**
     * @return array{event: array{name: string, locale: string, locales: list<string>}, organization: array{name: string, slug: string}, turnstileSiteKey: string, linkRequested: bool, tooManyRequests: bool, maxLinks: int, expiresInMinutes: int, backUrl: ?string}
     */
    public static function from(Organization $organization, Event $event, string $locale, bool $linkRequested = false, bool $tooManyRequests = false, ?string $callSlug = null): array
    {
        return [
            'event' => ['name' => $event->getTranslation('name', $locale), 'locale' => $locale, 'locales' => $event->offeredLocales()],
            'organization' => ['name' => $organization->name, 'slug' => $organization->slug],
            'turnstileSiteKey' => VerifyTurnstile::siteKey(),
            'linkRequested' => $linkRequested,
            'tooManyRequests' => $tooManyRequests,
            'maxLinks' => (int) config('submissions.author.max_links_per_email'),
            'expiresInMinutes' => (int) config('submissions.author.access_link_minutes'),
            'backUrl' => AccessLinkBackUrl::for($event, $organization, $locale, $callSlug),
        ];
    }

    /** La indicación `?call=` de la dirección, o `null` si falta o no es un texto razonable. */
    public static function callHint(mixed $value): ?string
    {
        return is_string($value) && $value !== '' && strlen($value) <= 100 ? $value : null;
    }
}
