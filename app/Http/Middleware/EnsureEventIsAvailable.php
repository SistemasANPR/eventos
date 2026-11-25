<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\PublicPageUnavailability;
use App\Models\Event;
use App\Models\Organization;
use App\Support\Events\PublicPageAvailability;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * El único punto de decisión de las páginas públicas de un evento (plan 030, H-030-2). Se aplica a toda ruta
 * `events/{event}/...` que muestra una página o recibe el envío; la pregunta la responde `PublicPageAvailability`,
 * la misma regla que usa el panel.
 *
 * - Evento publicado: sigue su camino (la convocatoria en borrador la sigue rechazando su controlador con 404).
 * - Evento archivado: 410 con la página «ya no está disponible» (solo el nombre del evento y de la organización;
 *   CA-030-8 y 9). El envío (POST) no llega al controlador: no se crea nada y la respuesta es JSON sin datos.
 * - Evento en borrador: 404, igual que uno inexistente (CA-030-10).
 *
 * Va DESPUÉS del binding de modelos (`bootstrap/app.php`): un evento o una convocatoria inexistentes, o de otra
 * organización, ya respondieron 404 antes de llegar aquí (CA-030-11). Las rutas de medios no la usan (CA-030-13).
 */
final class EnsureEventIsAvailable
{
    /** Idiomas de los textos fijos de la página «no disponible»; no depende de los idiomas del evento. */
    private const LOCALES = ['es', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $event = $request->route('event');
        $organization = $request->route('organization');

        if (! $event instanceof Event || ! $organization instanceof Organization) {
            throw new \LogicException('EnsureEventIsAvailable necesita el evento y la organización ya resueltos por la ruta.');
        }

        return match (PublicPageAvailability::reasonFor($event)) {
            null => $next($request),
            PublicPageUnavailability::Archived => $this->gone($request, $event, $organization),
            PublicPageUnavailability::DraftEvent, PublicPageUnavailability::DraftCall => abort(404),
        };
    }

    private function gone(Request $request, Event $event, Organization $organization): Response
    {
        $locale = $this->locale($request);
        app()->setLocale($locale);

        $response = $request->isMethodSafe()
            ? Inertia::render('Events/Unavailable', [
                'event' => ['name' => $event->getTranslation('name', $locale)],
                'organization' => ['name' => $organization->name, 'slug' => $organization->slug],
            ])->toResponse($request)->setStatusCode(410)
            : response()->json(['code' => 'EVENT_UNAVAILABLE', 'message' => __('public.unavailable.submit_error')], 410);

        // No se indexa ni se guarda en cachés compartidas: un evento archivado puede cambiar de estado de nuevo.
        $response->headers->set('X-Robots-Tag', 'noindex');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /** `?lang=` si es es o en; si no, el mejor de `Accept-Language` entre ellos; si no, el idioma base del sistema. */
    private function locale(Request $request): string
    {
        $requested = $request->query('lang');

        if (is_string($requested) && in_array($requested, self::LOCALES, true)) {
            return $requested;
        }

        foreach ($request->getLanguages() as $language) {
            $primary = strtolower(substr($language, 0, 2));

            if (in_array($primary, self::LOCALES, true)) {
                return $primary;
            }
        }

        $base = config('app.locale');

        return is_string($base) && in_array($base, self::LOCALES, true) ? $base : self::LOCALES[0];
    }
}
