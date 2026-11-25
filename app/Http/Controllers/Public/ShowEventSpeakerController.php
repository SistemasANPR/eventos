<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Actions\Program\FindPublicSpeaker;
use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Organization;
use App\Support\Events\PublicEventData;
use App\Support\Program\PublicActivityData;
use App\Support\Program\PublicProgramLinks;
use App\Support\Program\PublicSpeakerData;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ficha pública de un ponente (plan 029, DA-2, DA-6, CA-029-9):
 * `/{organization:slug}/events/{event:slug}/speakers/{speaker}`, con el uuid como identificador. Un
 * ponente no visible, de otro evento u organización, o un identificador que no es uuid responden el
 * mismo 404 que un evento inexistente, sin revelar si el registro existe.
 */
final class ShowEventSpeakerController
{
    public function __invoke(Request $request, Organization $organization, Event $event, string $speaker, FindPublicSpeaker $find): Response
    {
        abort_unless($event->isPublished(), 404);

        $found = $find->handle($event, $speaker) ?? abort(404);

        $locale = PublicEventData::resolveLocale($request, $event);
        app()->setLocale($locale);

        return Inertia::render('Events/Speaker', [
            'event' => PublicEventData::from($event, $organization, $locale),
            'organization' => ['name' => $organization->name, 'slug' => $organization->slug],
            'speaker' => PublicSpeakerData::detail($found, $event),
            'activities' => $found->activities
                ->map(static fn (EventActivity $activity): array => PublicActivityData::summary($activity, $event))
                ->values()
                ->all(),
            'nav' => PublicProgramLinks::nav($organization, $event, PublicProgramLinks::explicitLanguage($request, $event)),
        ]);
    }
}
