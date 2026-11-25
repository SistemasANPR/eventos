<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Actions\Program\FindPublicActivity;
use App\Models\Event;
use App\Models\Organization;
use App\Support\Events\PublicEventData;
use App\Support\Program\PublicActivityData;
use App\Support\Program\PublicProgramLinks;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Detalle público de una actividad (plan 029, DA-2, DA-3, CA-029-10):
 * `/{organization:slug}/events/{event:slug}/activities/{activity}`, con el uuid como identificador.
 * Una actividad no visible (no publicada, oculta por reversa, sin programar o borrada), de otro evento
 * u organización, o un identificador que no es uuid responden el mismo 404 que un evento inexistente.
 */
final class ShowEventActivityController
{
    public function __invoke(Request $request, Organization $organization, Event $event, string $activity, FindPublicActivity $find): Response
    {
        abort_unless($event->isPublished(), 404);

        $found = $find->handle($event, $activity) ?? abort(404);

        $locale = PublicEventData::resolveLocale($request, $event);
        app()->setLocale($locale);

        return Inertia::render('Events/Activity', [
            'event' => PublicEventData::from($event, $organization, $locale),
            'organization' => ['name' => $organization->name, 'slug' => $organization->slug],
            'activity' => PublicActivityData::detail($found, $event),
            'nav' => PublicProgramLinks::nav($organization, $event, PublicProgramLinks::explicitLanguage($request, $event)),
        ]);
    }
}
