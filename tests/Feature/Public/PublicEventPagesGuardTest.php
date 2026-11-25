<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureEventIsAvailable;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

/*
 * Guardiana del plan 030 (F2): toda ruta de `events/{event}/...` pasa por `EnsureEventIsAvailable`, el punto
 * único que decide el 410 «no disponible» y el 404, salvo las de medios (siguen en 404, CA-030-13), la subida de
 * archivos (JSON, con su propia compuerta) y el acceso del autor (guard propio, plan 006). Una ruta pública
 * nueva que olvide el punto compartido vuelve al 404 mudo: esta prueba falla y obliga a decidir.
 */

/** Rutas que no son una página del evento: se identifican por nombre para que añadir una sea una decisión explícita. */
const EVENT_ROUTES_WITHOUT_AVAILABILITY_GATE = [
    'public.events.logo',
    'public.speakers.photo',
    'public.activities.media',
    'public.landings.image',
    'calls.files.store',
];

/** @return list<Route> */
function publicEventRoutes(): array
{
    return array_values(array_filter(
        RouteFacade::getRoutes()->getRoutes(),
        static fn (Route $route): bool => str_starts_with($route->uri(), '{organization}/events/{event}')
            && ! str_contains($route->uri(), '/my-submission'),
    ));
}

it('CA-030-8: every public page route of an event goes through the availability gate', function (): void {
    $pages = array_filter(
        publicEventRoutes(),
        static fn (Route $route): bool => ! in_array($route->getName(), EVENT_ROUTES_WITHOUT_AVAILABILITY_GATE, true),
    );

    expect($pages)->not->toBeEmpty();

    $ungated = array_map(
        static fn (Route $route): string => ($route->getName() ?? $route->uri()),
        array_filter($pages, static fn (Route $route): bool => ! in_array(EnsureEventIsAvailable::class, $route->gatherMiddleware(), true)),
    );

    expect(array_values($ungated))->toBe([], 'Rutas públicas de evento sin EnsureEventIsAvailable: '.implode(', ', $ungated));
});

it('CA-030-13: the media and upload routes stay outside the gate, so they keep answering 404', function (): void {
    $names = array_map(static fn (Route $route): ?string => $route->getName(), publicEventRoutes());

    foreach (EVENT_ROUTES_WITHOUT_AVAILABILITY_GATE as $name) {
        expect($names)->toContain($name);
    }

    foreach (publicEventRoutes() as $route) {
        if (in_array($route->getName(), EVENT_ROUTES_WITHOUT_AVAILABILITY_GATE, true)) {
            expect($route->gatherMiddleware())->not->toContain(EnsureEventIsAvailable::class);
        }
    }
});
