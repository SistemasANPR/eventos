<?php

declare(strict_types=1);

namespace App\Http\Controllers\Author;

use App\Actions\Submissions\ResolveAuthorSubmission;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\Organization;
use App\Support\Auth\AuthorSession;
use App\Support\Calls\ResolveCallState;
use Illuminate\Http\RedirectResponse;

/**
 * Cierra la sesión del autor: `POST /{organization:slug}/events/{event:slug}/my-submission/logout`
 * (plan 034, DA-5). Solo POST con CSRF, dentro del grupo con sesión de autor. Vuelve a la página de la
 * convocatoria de la propuesta; si esa convocatoria ya no es pública, a la del evento.
 */
final class LogoutAuthorController
{
    public function __invoke(Organization $organization, Event $event, AuthorSession $session, ResolveAuthorSubmission $resolve): RedirectResponse
    {
        $author = $session->current();
        $call = $author === null ? null : $this->callOf($resolve->handle($author, $event)->call_for_submissions_id);

        $session->logout();

        $route = ['organization' => $organization->slug, 'event' => $event->slug];

        return $call instanceof CallForSubmissions
            ? to_route('calls.show', $route + ['call' => $call->slug], 303)
            : to_route('events.show', $route, 303);
    }

    private function callOf(?string $callId): ?CallForSubmissions
    {
        $call = $callId === null ? null : CallForSubmissions::query()->find($callId);

        return $call instanceof CallForSubmissions && ResolveCallState::for($call) !== null ? $call : null;
    }
}
