<?php

declare(strict_types=1);

namespace App\Http\Controllers\Author;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Organization;
use App\Support\Events\PublicEventData;
use App\Support\Submissions\AccessLinkPageData;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Página «pedir mi enlace de acceso»: `/{organization:slug}/events/{event:slug}/my-submission/request-link`
 * (plan 006, H-006-3). Es el destino de quien no tiene sesión y de los correos de resultado (DA-3). Un evento
 * en borrador responde 404; no lleva datos de ninguna propuesta.
 *
 * `linkRequested` viene de la sesión (lo deja `RequestAccessLinkController`) y es el mismo aviso
 * exista o no una propuesta con ese correo (CA-006-32).
 */
final class ShowAccessLinkRequestController
{
    public function __invoke(Request $request, Organization $organization, Event $event): Response
    {
        abort_if($event->status === EventStatus::Draft, 404);

        $locale = PublicEventData::resolveLocale($request, $event);
        app()->setLocale($locale);

        return Inertia::render('Author/RequestLink', AccessLinkPageData::from(
            $organization,
            $event,
            $locale,
            linkRequested: (bool) $request->session()->pull('author.link_requested', false),
        ));
    }
}
