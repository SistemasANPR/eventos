<?php

declare(strict_types=1);

use App\Support\Auth\AccessThrottle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('CA-001-27: allows exactly five attempts per window and refuses the sixth', function (): void {
    $throttle = new AccessThrottle;
    $buckets = ['test-bucket:'.Str::random(8)];

    foreach (range(1, AccessThrottle::MAX_ATTEMPTS) as $ignored) {
        $throttle->hitOrFail('login_throttled', $buckets, []);
    }

    expect(fn () => $throttle->hitOrFail('login_throttled', $buckets, []))
        ->toThrow(ThrottleRequestsException::class);
});

it('CA-001-27: counts the attempt before deciding, so parallel requests cannot all slip under the limit', function (): void {
    $throttle = new AccessThrottle;
    $bucket = 'test-bucket:'.Str::random(8);

    foreach (range(1, AccessThrottle::MAX_ATTEMPTS) as $ignored) {
        $throttle->hitOrFail('login_throttled', [$bucket], []);
    }

    try {
        $throttle->hitOrFail('login_throttled', [$bucket], []);
    } catch (ThrottleRequestsException) {
        // Esperado: la sexta se rechaza.
    }

    // Comprobar-y-luego-contar dejaría el contador en 5; contar primero lo deja en 6.
    expect(RateLimiter::attempts($bucket))->toBe(AccessThrottle::MAX_ATTEMPTS + 1);
});

it('CA-001-29: a full bucket refuses the attempt even if the other buckets still have room', function (): void {
    $throttle = new AccessThrottle;
    $full = 'test-bucket:full:'.Str::random(8);
    $empty = 'test-bucket:empty:'.Str::random(8);

    foreach (range(1, AccessThrottle::MAX_ATTEMPTS) as $ignored) {
        $throttle->hitOrFail('password_reset_throttled', [$full], []);
    }

    expect(fn () => $throttle->hitOrFail('password_reset_throttled', [$empty, $full], []))
        ->toThrow(ThrottleRequestsException::class);
});
