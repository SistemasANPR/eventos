<?php

declare(strict_types=1);

namespace App\Http\Controllers\Author;

use App\Actions\Submissions\ConsumeAccessToken;
use App\Exceptions\Domain\AccessLinkInvalid;
use App\Http\Requests\Submissions\ConsumeAccessLinkRequest;
use App\Models\Event;
use App\Models\Organization;
use App\Support\Auth\AuthorSession;
use Illuminate\Http\RedirectResponse;

/**
 * Confirmar el enlace de acceso: `POST .../my-submission/access/{token}` (plan 006, DA-3, CA-006-34 a 36).
 * Consume el token de forma atómica, regenera la sesión y abre la sesión del autor para esa propuesta.
 * Un enlace que no sirve lanza `AccessLinkInvalid`, que se muestra siempre igual (410).
 *
 * @throws AccessLinkInvalid
 */
final class ConsumeAccessLinkController
{
    public function __invoke(
        ConsumeAccessLinkRequest $request,
        Organization $organization,
        Event $event,
        ConsumeAccessToken $consume,
        AuthorSession $session,
    ): RedirectResponse {
        $submission = $consume->handle($event, $request->token());

        $session->open($submission);

        // 303 a una URL sin el token: el enlace de un solo uso no queda en el historial como destino.
        return to_route('author.submission.show', ['organization' => $organization->slug, 'event' => $event->slug], 303);
    }
}
