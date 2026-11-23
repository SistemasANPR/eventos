<?php

declare(strict_types=1);

use App\Support\Submissions\PublicFormThrottle;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    Cache::flush();
});

it('CA-006-20: una misma IP recibe 429 con Retry-After al superar 5 envios en 10 minutos', function (): void {
    $throttle = app(PublicFormThrottle::class);

    foreach (range(1, 5) as $i) {
        $throttle->hitSubmission('203.0.113.9', "autor{$i}@example.test");
    }

    try {
        $throttle->hitSubmission('203.0.113.9', 'otro@example.test');
        $this->fail('Debio responder 429.');
    } catch (ThrottleRequestsException $exception) {
        expect($exception->getStatusCode())->toBe(429)
            ->and($exception->getHeaders())->toHaveKey('Retry-After');
    }

    // Otra IP no se ve afectada.
    $throttle->hitSubmission('203.0.113.10', 'autor@example.test');
});

it('CA-006-26: un correo con 3 acuses en la ultima hora recibe 429 en el cuarto envio', function (): void {
    $throttle = app(PublicFormThrottle::class);

    foreach (range(1, 3) as $i) {
        $throttle->hitSubmission("198.51.100.{$i}", 'Autor@Example.test');
    }

    expect(fn () => $throttle->hitSubmission('198.51.100.40', ' autor@example.test '))
        ->toThrow(ThrottleRequestsException::class);
});

it('CA-006-25: las solicitudes de enlace se limitan por correo y por IP sin revelar si el correo existe', function (): void {
    $throttle = app(PublicFormThrottle::class);

    foreach (range(1, 3) as $i) {
        $throttle->hitAccessLink("198.51.100.{$i}", 'autor@example.test');
    }

    // Mismo correo desde otra IP: 429 por correo.
    expect(fn () => $throttle->hitAccessLink('198.51.100.9', 'autor@example.test'))
        ->toThrow(ThrottleRequestsException::class);

    // Misma IP con 10 correos distintos: el undecimo recibe 429 por IP.
    foreach (range(1, 10) as $i) {
        $throttle->hitAccessLink('192.0.2.1', "persona{$i}@example.test");
    }

    expect(fn () => $throttle->hitAccessLink('192.0.2.1', 'persona11@example.test'))
        ->toThrow(ThrottleRequestsException::class);
});

it('CA-006-25: la clave del limite por correo no contiene el correo en claro', function (): void {
    app(PublicFormThrottle::class)->hitAccessLink('198.51.100.1', 'secreto@example.test');

    $key = PublicFormThrottle::emailKey('access-link', ' Secreto@Example.test ');

    expect($key)->not->toContain('secreto')
        ->and($key)->not->toContain('example.test')
        ->and(RateLimiter::attempts($key))->toBe(1);
});

it('SUBMISSIONS_LIMIT_MULTIPLIER escala los intentos de los limites y nunca baja de 1', function (): void {
    $load = function (string $value): array {
        putenv("SUBMISSIONS_LIMIT_MULTIPLIER={$value}");
        $_ENV['SUBMISSIONS_LIMIT_MULTIPLIER'] = $value;
        $_SERVER['SUBMISSIONS_LIMIT_MULTIPLIER'] = $value;

        return require config_path('submissions.php');
    };

    try {
        $default = $load('1');
        $scaled = $load('100');
        $invalid = $load('0');
    } finally {
        putenv('SUBMISSIONS_LIMIT_MULTIPLIER');
        unset($_ENV['SUBMISSIONS_LIMIT_MULTIPLIER'], $_SERVER['SUBMISSIONS_LIMIT_MULTIPLIER']);
    }

    expect($default['limits']['submission_per_ip']['attempts'])->toBe(5)
        ->and($scaled['limits']['submission_per_ip']['attempts'])->toBe(500)
        ->and($scaled['limits']['received_per_email']['attempts'])->toBe(300)
        ->and($scaled['upload_quota']['per_ip']['attempts'])->toBe(3000)
        ->and($scaled['limits']['submission_per_ip']['decay_seconds'])->toBe(600)
        ->and($invalid['limits']['submission_per_ip']['attempts'])->toBe(5);
});
