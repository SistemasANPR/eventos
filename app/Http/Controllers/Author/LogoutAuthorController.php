<?php

declare(strict_types=1);

namespace App\Http\Controllers\Author;

use App\Actions\Submissions\ResolveAuthorSubmission;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\Organization;
use App\Support\Auth\AuthorSession;
use App\Support\Events\PublicPageAvailability;
use Illuminate\Http\RedirectResponse;

/**
 * Cierra la sesión del autor: `POST /{organization:slug}/events/{event:slug}/my-submission/logout`
 * (plan 034, DA-5). Solo POST con CSRF, dentro del grupo con sesión de autor. Vuelve a la página de la
 * convocatoria de la propuesta; si esa convocatoria ya no es pública, a la del evento; y si el evento
 * tampoco tiene página (archivado), a pedir un enlace de acceso. Qué páginas existen lo decide
 * `PublicPageAvailability` (plan 032, CA-032-7).
 */
final class LogoutAuthorController
{
    public function __invoke(Organization $organization, Event $event, AuthorSession $session, ResolveAuthorSubmission $resolve): RedirectResponse
    {
        $author = $session->current();
        $call = $author === null ? null : $this->callOf($resolve->handle($author, $event)->call_for_submissions_id);

        $session->logout();

        $route = ['organization' => $organization->slug, 'event' => $event->slug];

        if ($call instanceof CallForSubmissions && PublicPageAvailability::isAvailable($event, $call)) {
            return to_route('calls.show', $route + ['call' => $call->slug], 303);
        }

        $eventUrl = PublicPageAvailability::eventUrl($event, $organization);

        return $eventUrl === null ? to_route('author.request-link.show', $route, 303) : redirect($eventUrl, 303);
    }

    private function callOf(?string $callId): ?CallForSubmissions
    {
        return $callId === null ? null : CallForSubmissions::query()->find($callId);
    }
}
