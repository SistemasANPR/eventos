<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Models\Event;
use App\Models\Organization;
use App\Support\Events\PublicEventData;
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
    public function __invoke(Request $request, Organization $organization, Event $event): Response
    {
        abort_unless($event->isPublished(), 404);

        $locale = PublicEventData::resolveLocale($request, $event);
        app()->setLocale($locale);

        return Inertia::render('Events/Show', [
            'event' => PublicEventData::from($event, $locale),
            'organization' => ['name' => $organization->name, 'slug' => $organization->slug],
        ]);
    }
}
