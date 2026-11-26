<?php

declare(strict_types=1);

namespace App\Http\Controllers\Author;

use App\Actions\Submissions\SendAccessLink;
use App\Actions\Submissions\VerifyTurnstile;
use App\Exceptions\Domain\TurnstileInvalid;
use App\Exceptions\Domain\TurnstileUnavailable;
use App\Http\Requests\Submissions\RequestAccessLinkRequest;
use App\Models\Event;
use App\Models\Organization;
use App\Support\Events\PublicEventData;
use App\Support\Submissions\AccessLinkPageData;
use App\Support\Submissions\PublicFormThrottle;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pedir un enlace de acceso: `POST .../my-submission/request-link` (plan 006, CA-006-25, CA-006-30 a 32).
 *
 * Orden: validar → límite de peticiones (por IP y por correo, sin distinguir si el correo existe) →
 * Turnstile → `SendAccessLink`, que solo ENCOLA. La respuesta es SIEMPRE la misma redirección con el mismo
 * aviso, haya o no propuestas con ese correo, para no revelar quién tiene una (CA-006-32).
 */
final class RequestAccessLinkController
{
    public function __invoke(
        RequestAccessLinkRequest $request,
        Organization $organization,
        Event $event,
        PublicFormThrottle $throttle,
        VerifyTurnstile $turnstile,
        SendAccessLink $sendLink,
    ): RedirectResponse|Response {
        $locale = PublicEventData::resolveLocale($request, $event);
        app()->setLocale($locale);
        $email = $request->string('email')->toString();

        try {
            $throttle->hitAccessLink((string) $request->ip(), $email);
            $turnstile->handle($request->string(RequestAccessLinkRequest::TURNSTILE_FIELD)->toString(), $request->ip());
        } catch (ThrottleRequestsException $exception) {
            return $this->tooManyRequests($request, $organization, $event, $locale, $exception);
        } catch (TurnstileInvalid|TurnstileUnavailable $exception) {
            throw ValidationException::withMessages([RequestAccessLinkRequest::TURNSTILE_FIELD => $exception->getMessage()]);
        }

        $sendLink->handle($event, $email);

        $request->session()->put('author.link_requested', true);

        // La indicación de la convocatoria se conserva para que «Regresar» siga llevando a ella (plan 034, DA-7).
        return to_route('author.request-link.show', array_filter([
            'organization' => $organization->slug,
            'event' => $event->slug,
            'lang' => $locale,
            'call' => AccessLinkPageData::callHint($request->query('call')),
        ]), 303);
    }

    /** 429 con `Retry-After`, en la misma página y con el mensaje en pantalla (CA-006-25). */
    private function tooManyRequests(RequestAccessLinkRequest $request, Organization $organization, Event $event, string $locale, ThrottleRequestsException $exception): Response
    {
        $response = Inertia::render('Author/RequestLink', AccessLinkPageData::from($organization, $event, $locale, tooManyRequests: true, callSlug: AccessLinkPageData::callHint($request->query('call'))))
            ->toResponse($request)
            ->setStatusCode(429);
        $response->headers->add($exception->getHeaders());

        return $response;
    }
}
