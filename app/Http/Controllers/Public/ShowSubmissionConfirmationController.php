<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\Organization;
use App\Support\Calls\PublicCallData;
use App\Support\Events\PublicEventData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Confirmación del envío: `GET /{organization:slug}/events/{event:slug}/call/{call:slug}/submitted`.
 * No lleva datos personales ni el identificador de la propuesta en la URL (CA-006-57): comprueba un
 * dato de sesión que fija el envío y, sin él, redirige a la convocatoria. El acuse con el detalle
 * llega por correo (F5).
 */
final class ShowSubmissionConfirmationController
{
    public static function sessionKey(CallForSubmissions $call): string
    {
        return 'submission_confirmed.'.$call->id;
    }

    public function __invoke(Request $request, Organization $organization, Event $event, CallForSubmissions $call): Response|RedirectResponse
    {
        abort_unless($event->isPublished(), 404);

        $route = ['organization' => $organization->slug, 'event' => $event->slug];
        $callRoute = $route + ['call' => $call->slug];

        if (! $request->session()->get(self::sessionKey($call), false)) {
            return redirect()->route('calls.show', $callRoute);
        }

        $locale = PublicCallData::resolveLocale($request, $call, $event);
        app()->setLocale($locale);

        return Inertia::render('Calls/Submitted', [
            'call' => ['title' => $call->getTranslation('title', $locale), 'locale' => $locale, 'locales' => $event->effectiveLocalesForCall($call)],
            'event' => ['name' => $event->getTranslation('name', $locale), 'logoUrl' => PublicEventData::logoUrl($event, $organization)],
            'organization' => ['name' => $organization->name, 'slug' => $organization->slug],
            'callUrl' => route('calls.show', $callRoute, false)."?lang={$locale}",
            'eventUrl' => route('events.show', $route, false)."?lang={$locale}",
        ]);
    }
}
