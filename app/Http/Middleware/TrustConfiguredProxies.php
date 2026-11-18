<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

/**
 * Confía solo en los proxies de `config('app.trusted_proxies')`.
 *
 * La configuración aún no está cargada cuando `bootstrap/app.php` registra los
 * middleware, por eso la lista se lee aquí, al atender cada petición. Lista
 * vacía = ningún proxy es de confianza (tampoco por detección de plataforma).
 */
class TrustConfiguredProxies extends TrustProxies
{
    protected function setTrustedProxyIpAddresses(Request $request): void
    {
        /** @var list<string> $trusted */
        $trusted = config('app.trusted_proxies', []);

        if ($trusted === []) {
            return;
        }

        if (in_array('*', $trusted, true)) {
            $this->setTrustedProxyIpAddressesToTheCallingIp($request);

            return;
        }

        $this->setTrustedProxyIpAddressesToSpecificIps($request, $trusted);
    }
}
