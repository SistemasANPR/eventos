<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Models\Event;
use App\Models\Organization;
use App\Support\Events\PublicEventData;
use DomainException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * El enlace de acceso del autor no sirve (plan 006, CA-006-36). Un solo mensaje genérico para todos los
 * casos —consumido, caducado, alterado, inexistente, de otra organización o de otro evento—, de modo que
 * quien lo usa no pueda saber cuál fue. Se muestra como página (410) con la invitación a pedir otro enlace.
 */
final class AccessLinkInvalid extends DomainException
{
    public const CODE = 'ACCESS_LINK_INVALID';

    public function __construct()
    {
        parent::__construct(__('author.access_link_invalid'));
    }

    public function render(Request $request): Response
    {
        $organization = $request->route('organization');
        $event = $request->route('event');

        abort_unless($organization instanceof Organization && $event instanceof Event, 404);

        app()->setLocale(PublicEventData::resolveLocale($request, $event));

        return Inertia::render('Author/AccessInvalid', [
            'event' => ['name' => $event->getTranslation('name', app()->getLocale())],
            'organization' => ['name' => $organization->name, 'slug' => $organization->slug],
            'requestLinkUrl' => route('author.request-link.show', ['organization' => $organization->slug, 'event' => $event->slug], false),
        ])->toResponse($request)->setStatusCode(Response::HTTP_GONE);
    }
}
