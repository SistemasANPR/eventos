<?php

declare(strict_types=1);

use App\Actions\Submissions\VerifyTurnstile;
use App\Exceptions\Domain\TurnstileInvalid;
use App\Exceptions\Domain\TurnstileUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    config([
        'services.turnstile.site_key' => 'site-publica',
        'services.turnstile.secret' => 'secreta-solo-servidor',
        'services.turnstile.verify_url' => 'https://turnstile.test/siteverify',
    ]);
    Cache::flush();
});

it('CA-006-21: acepta un token que el servicio declara valido y manda la llave secreta solo desde la configuracion', function (): void {
    Http::fake(['turnstile.test/*' => Http::response(['success' => true])]);

    app(VerifyTurnstile::class)->handle('token-bueno', '203.0.113.9');

    Http::assertSent(fn (Request $request): bool => $request['secret'] === 'secreta-solo-servidor'
        && $request['response'] === 'token-bueno'
        && $request['remoteip'] === '203.0.113.9');
});

it('CA-006-21: rechaza un envio sin token sin llamar al servicio', function (?string $token): void {
    Http::fake();

    expect(fn () => app(VerifyTurnstile::class)->handle($token, '203.0.113.9'))
        ->toThrow(TurnstileInvalid::class);

    Http::assertNothingSent();
})->with([null, '', '   ']);

it('CA-006-21: rechaza un token que el servicio declara invalido', function (): void {
    Http::fake(['turnstile.test/*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);

    expect(fn () => app(VerifyTurnstile::class)->handle('token-malo', '203.0.113.9'))
        ->toThrow(TurnstileInvalid::class);
});

it('CA-006-22: si el servicio no responde, cierra el acceso con un aviso para reintentar y registra la falla sin datos personales', function (): void {
    Http::fake(fn () => throw new ConnectionException('timeout'));
    Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context = []): bool {
        $dump = $message.json_encode($context, JSON_THROW_ON_ERROR);

        return ! str_contains($dump, '203.0.113.9') && ! str_contains($dump, 'token-x') && ! str_contains($dump, 'secreta-solo-servidor');
    });

    expect(fn () => app(VerifyTurnstile::class)->handle('token-x', '203.0.113.9'))
        ->toThrow(TurnstileUnavailable::class);
});

it('CA-006-22: una respuesta 5xx del servicio tambien cierra el acceso', function (): void {
    Http::fake(['turnstile.test/*' => Http::response('error', 503)]);

    expect(fn () => app(VerifyTurnstile::class)->handle('token-x', null))
        ->toThrow(TurnstileUnavailable::class);
});

it('CA-006-23: la llave publica es la unica que se expone y la secreta nunca va en las props', function (): void {
    expect(VerifyTurnstile::siteKey())->toBe('site-publica')
        ->and(VerifyTurnstile::siteKey())->not->toBe(config('services.turnstile.secret'));
});

it('CA-006-24: un token ya usado en un envio se rechaza en el siguiente', function (): void {
    Http::fake(['turnstile.test/*' => Http::response(['success' => true])]);

    app(VerifyTurnstile::class)->handle('token-unico', '203.0.113.9');

    expect(fn () => app(VerifyTurnstile::class)->handle('token-unico', '203.0.113.9'))
        ->toThrow(TurnstileInvalid::class);

    Http::assertSentCount(1);
});

it('CA-006-24: el token no se guarda en claro en la cache', function (): void {
    Http::fake(['turnstile.test/*' => Http::response(['success' => true])]);

    app(VerifyTurnstile::class)->handle('token-secreto-123', null);

    expect(Cache::has('turnstile:used:token-secreto-123'))->toBeFalse()
        ->and(Cache::has('turnstile:used:'.hash('sha256', 'token-secreto-123')))->toBeTrue();
});
