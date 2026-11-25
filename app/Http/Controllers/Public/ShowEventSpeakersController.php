<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Actions\Program\ListPublicSpeakers;
use App\Models\Event;
use App\Models\Organization;
use App\Models\PrivacyNotice;
use App\Models\Speaker;
use App\Support\Events\PublicEventData;
use App\Support\Program\PublicProgramLinks;
use App\Support\Program\PublicSpeakerData;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lista pública de ponentes de un evento publicado, paginada (plan 029, DA-6, DA-7, CA-029-8):
 * `/{organization:slug}/events/{event:slug}/speakers?page=N`. El evento no publicado o de otra
 * organización responde 404; sin ponentes visibles responde 200 y la página lo dice.
 */
final class ShowEventSpeakersController
{
    public function __invoke(Request $request, Organization $organization, Event $event, ListPublicSpeakers $list): Response
    {
        abort_unless($event->isPublished(), 404);

        $locale = PublicEventData::resolveLocale($request, $event);
        app()->setLocale($locale);

        $speakers = $list->handle($event, PublicProgramLinks::page($request));
        $language = PublicProgramLinks::explicitLanguage($request, $event);
        $parameters = PublicProgramLinks::parameters($organization, $event);

        return Inertia::render('Events/Speakers', [
            'event' => PublicEventData::from($event, $organization, $locale),
            'organization' => ['name' => $organization->name, 'slug' => $organization->slug],
            'speakers' => [
                'data' => $speakers->getCollection()
                    ->map(static fn (Speaker $speaker): array => PublicSpeakerData::summary($speaker, $event))
                    ->values()
                    ->all(),
                'currentPage' => $speakers->currentPage(),
                'lastPage' => $speakers->lastPage(),
                'prevUrl' => $speakers->currentPage() > 1
                    ? self::pageUrl($parameters, $language, $speakers->currentPage() - 1)
                    : null,
                'nextUrl' => $speakers->hasMorePages()
                    ? self::pageUrl($parameters, $language, $speakers->currentPage() + 1)
                    : null,
            ],
            'nav' => PublicProgramLinks::nav($organization, $event, $language),
            // Solo se enlaza si hay una versión PUBLICADA (CA-003-19); un borrador no cuenta.
            'privacyUrl' => PrivacyNotice::currentPublished($event) === null
                ? null
                : route('events.privacy', $parameters, false),
        ]);
    }

    /** @param  array{organization: string, event: string}  $parameters */
    private static function pageUrl(array $parameters, ?string $language, int $page): string
    {
        return route('events.speakers.index', [
            ...$parameters,
            ...PublicProgramLinks::languageQuery($language),
            ...($page > 1 ? ['page' => (string) $page] : []),
        ], false);
    }
}
