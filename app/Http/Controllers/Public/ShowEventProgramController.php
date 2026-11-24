<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Actions\Program\ListPublicProgramDay;
use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Organization;
use App\Models\PrivacyNotice;
use App\Support\Events\PublicEventData;
use App\Support\Program\PublicActivityData;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Programa público de un evento publicado, un día por vista (plan 029, DA-4):
 * `/{organization:slug}/events/{event:slug}/program?day=AAAA-MM-DD&page=N`. La organización queda en
 * contexto por `SetOrganizationFromRoute` antes del binding; el evento de otra organización, uno en
 * borrador o archivado responden 404, igual que uno inexistente. Un `day` que no es un día con
 * actividades cae al primero sin error. Evento sin actividades visibles: 200 y la página lo dice.
 */
final class ShowEventProgramController
{
    public function __invoke(Request $request, Organization $organization, Event $event, ListPublicProgramDay $list): Response
    {
        abort_unless($event->isPublished(), 404);

        $locale = PublicEventData::resolveLocale($request, $event);
        app()->setLocale($locale);

        $program = $list->handle($event, $this->day($request), $this->page($request));
        $language = $this->explicitLanguage($request, $event);
        $parameters = ['organization' => $organization->slug, 'event' => $event->slug];

        return Inertia::render('Events/Program', [
            'event' => PublicEventData::from($event, $organization, $locale),
            'organization' => ['name' => $organization->name, 'slug' => $organization->slug],
            'days' => $program->days,
            'day' => $program->day,
            'activities' => [
                'data' => $program->activities->getCollection()
                    ->map(static fn (EventActivity $activity): array => PublicActivityData::summary($activity, $event))
                    ->values()
                    ->all(),
                'currentPage' => $program->activities->currentPage(),
                'lastPage' => $program->activities->lastPage(),
                'prevUrl' => $program->activities->currentPage() > 1
                    ? $this->programUrl($parameters, $language, $program->day, $program->activities->currentPage() - 1)
                    : null,
                'nextUrl' => $program->activities->hasMorePages()
                    ? $this->programUrl($parameters, $language, $program->day, $program->activities->currentPage() + 1)
                    : null,
            ],
            'categories' => $program->categories
                ->map(static fn ($category): array => ['id' => $category->id, 'name' => $category->name])
                ->values()
                ->all(),
            'nav' => [
                'programUrl' => $this->programUrl($parameters, $language),
                // La página de ponentes llega en F3; hasta entonces no hay a dónde enlazar.
                'speakersUrl' => null,
                'eventUrl' => route('events.show', [...$parameters, ...$this->languageQuery($language)], false),
            ],
            // Solo se enlaza si hay una versión PUBLICADA (CA-003-19); un borrador no cuenta.
            'privacyUrl' => PrivacyNotice::currentPublished($event) === null
                ? null
                : route('events.privacy', $parameters, false),
        ]);
    }

    /** `?day=` solo cuenta si es texto: un arreglo u otro valor se ignora y cae al primer día. */
    private function day(Request $request): ?string
    {
        $day = $request->query('day');

        return is_string($day) ? $day : null;
    }

    /** `?page=` solo cuenta si es un entero positivo; cualquier otro valor es la primera página. */
    private function page(Request $request): int
    {
        $page = $request->query('page');

        return is_string($page) && ctype_digit($page) ? max(1, (int) $page) : 1;
    }

    /** El idioma que el visitante pidió con `?lang=` y el evento ofrece: es el único que se conserva en los enlaces. */
    private function explicitLanguage(Request $request, Event $event): ?string
    {
        $requested = $request->query('lang');

        return is_string($requested) && in_array($requested, $event->offeredLocales(), true) ? $requested : null;
    }

    /** @return array<string, string> */
    private function languageQuery(?string $language): array
    {
        return $language === null ? [] : ['lang' => $language];
    }

    /** @param  array{organization: string, event: string}  $parameters */
    private function programUrl(array $parameters, ?string $language, ?string $day = null, int $page = 1): string
    {
        return route('events.program', [
            ...$parameters,
            ...$this->languageQuery($language),
            ...($day === null ? [] : ['day' => $day]),
            ...($page > 1 ? ['page' => (string) $page] : []),
        ], false);
    }
}
