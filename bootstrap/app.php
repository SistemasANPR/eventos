<?php

declare(strict_types=1);

use App\Http\Middleware\SetOrganizationFromRoute;
use App\Http\Middleware\TrustConfiguredProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Los proxies de confianza vienen de `config('app.trusted_proxies')` (TRUSTED_PROXIES,
        // vacío por defecto). Solo las cabeceras X-Forwarded-For/Host/Port/Proto; no las de AWS ELB.
        $middleware->replace(TrustProxies::class, TrustConfiguredProxies::class);
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);

        // La organización se fija antes del binding de modelos con tenant (CA-001-9).
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: SetOrganizationFromRoute::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
