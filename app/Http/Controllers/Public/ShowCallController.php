<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Enums\CallState;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\Organization;
use App\Models\PrivacyNotice;
use App\Support\Calls\CallLandingData;
use App\Support\Calls\PublicCallData;
use App\Support\Calls\ResolveCallState;
use App\Support\Events\PublicEventData;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Página pública de una convocatoria: `/{organization:slug}/events/{event:slug}/call/{call:slug}`.
 * `SetOrganizationFromRoute` fija la organización antes del binding y
 * `scopeBindings` acota la convocatoria al evento. Borrador, de otro evento u
 * organización, eliminada o con evento sin publicar responden 404 sin distinguirse.
 */
final class ShowCallController
{
    public function __invoke(Request $request, Organization $organization, Event $event, CallForSubmissions $call): Response
    {
        abort_unless($event->isPublished(), 404);

        $state = ResolveCallState::for($call);

        abort_if($state === null, 404);

        $locale = PublicCallData::resolveLocale($request, $call, $event);
        app()->setLocale($locale);

        $eventRoute = ['organization' => $organization->slug, 'event' => $event->slug];

        return Inertia::render('Calls/Show', [
            'call' => PublicCallData::from($call, $event, $state, $locale),
            'event' => ['name' => $event->getTranslation('name', $locale), 'logoUrl' => PublicEventData::logoUrl($event, $organization)],
            'organization' => ['name' => $organization->name, 'slug' => $organization->slug],
            // La landing se arma con estos datos; los nombres solo sirven para encontrar el texto propio de una convocatoria, si lo tiene.
            'slugs' => ['organization' => $organization->slug, 'event' => $event->slug, 'call' => $call->slug],
            'landing' => CallLandingData::from($call, $event, $organization, $locale),
            'eventUrl' => route('events.show', $eventRoute, false)."?lang={$locale}",
            // Solo con la convocatoria abierta se ofrece el botón de envío (plan 006, H-006-7).
            'submitUrl' => $state === CallState::Open
                ? route('calls.submit.show', $eventRoute + ['call' => $call->slug], false)."?lang={$locale}"
                : null,
            // Solo se enlaza si hay una versión PUBLICADA (CA-003-19).
            'privacyUrl' => PrivacyNotice::currentPublished($event) === null
                ? null
                : route('events.privacy', $eventRoute, false),
        ]);
    }
}
