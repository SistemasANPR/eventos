<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::get('/_test/client-ip', fn () => response()->json([
        'ip' => request()->ip(),
        'secure' => request()->isSecure(),
    ]));
});

/**
 * Simula una petición que llega desde `$proxyIp` con cabeceras de reenvío. `$case` es el
 * `$this` de la prueba (Larastan lo ve como TestCall dentro de `it()`, de ahí `mixed`).
 *
 * @return array{ip: string, secure: bool}
 */
function requestThroughProxy(mixed $case, string $proxyIp): array
{
    return $case
        ->withServerVariables(['REMOTE_ADDR' => $proxyIp])
        ->withHeaders([
            'X-Forwarded-For' => '203.0.113.9',
            'X-Forwarded-Proto' => 'https',
        ])
        ->getJson('/_test/client-ip')
        ->assertOk()
        ->json();
}

it('trusts no proxy by default, so a spoofed X-Forwarded-For does not change the IP', function (): void {
    config(['app.trusted_proxies' => []]);

    $result = requestThroughProxy($this, '198.51.100.20');

    expect($result['ip'])->toBe('198.51.100.20')
        ->and($result['secure'])->toBeFalse();
});

it('honors the forwarded client IP when the caller is a listed proxy', function (): void {
    config(['app.trusted_proxies' => ['198.51.100.20']]);

    $result = requestThroughProxy($this, '198.51.100.20');

    expect($result['ip'])->toBe('203.0.113.9')
        ->and($result['secure'])->toBeTrue();
});

it('honors the forwarded client IP when the caller is inside a listed CIDR range', function (): void {
    config(['app.trusted_proxies' => ['100.64.0.0/10']]);

    expect(requestThroughProxy($this, '100.64.3.7')['ip'])->toBe('203.0.113.9');
});

it('ignores forwarded headers from a caller that is not in the list', function (): void {
    config(['app.trusted_proxies' => ['198.51.100.20']]);

    expect(requestThroughProxy($this, '192.0.2.77')['ip'])->toBe('192.0.2.77');
});

it('trusts every caller only when the wildcard is set explicitly', function (): void {
    config(['app.trusted_proxies' => ['*']]);

    expect(requestThroughProxy($this, '192.0.2.77')['ip'])->toBe('203.0.113.9');
});

it('parses the TRUSTED_PROXIES list from the app config file', function (): void {
    $parse = require base_path('config/app.php');

    expect($parse['trusted_proxies'])->toBe([]);
});
