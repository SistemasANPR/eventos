<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Exceptions\Domain\TurnstileInvalid;
use App\Exceptions\Domain\TurnstileUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Verifica el token de Cloudflare Turnstile del envío final (plan 006, H-006-2, DA-4).
 *
 * - Sin token, o con uno que el servicio rechaza: `TurnstileInvalid` (422).
 * - Un token ya usado se rechaza sin llamar al servicio; solo se guarda su hash.
 * - Si el servicio falla o no responde: `TurnstileUnavailable`; se cierra el acceso y la
 *   falla se registra sin token, IP ni llave. Solo se verifica en el envío final, no en las cargas.
 * - La llave secreta sale solo de la configuración del servidor.
 */
final class VerifyTurnstile
{
    private const USED_TOKEN_TTL_SECONDS = 600;

    private const REQUEST_TIMEOUT_SECONDS = 5;

    /** Llave pública que el formulario necesita; la secreta nunca se expone. */
    public static function siteKey(): string
    {
        return (string) config('services.turnstile.site_key');
    }

    /**
     * @throws TurnstileInvalid
     * @throws TurnstileUnavailable
     */
    public function handle(?string $token, ?string $ip): void
    {
        $token = trim((string) $token);

        if ($token === '') {
            throw TurnstileInvalid::make();
        }

        // `add` es atómico: de dos envíos con el mismo token, solo uno lo consume.
        $fresh = Cache::add('turnstile:used:'.hash('sha256', $token), true, self::USED_TOKEN_TTL_SECONDS);

        if (! $fresh) {
            throw TurnstileInvalid::make();
        }

        try {
            $response = Http::asForm()
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->post((string) config('services.turnstile.verify_url'), array_filter([
                    'secret' => (string) config('services.turnstile.secret'),
                    'response' => $token,
                    'remoteip' => $ip,
                ]));
        } catch (ConnectionException) {
            $this->unavailable('sin respuesta');
        }

        if ($response->serverError()) {
            $this->unavailable('error del servicio');
        }

        if ($response->json('success') !== true) {
            throw TurnstileInvalid::make();
        }
    }

    /** @return never */
    private function unavailable(string $reason): void
    {
        Log::warning('Turnstile no disponible: '.$reason);

        throw TurnstileUnavailable::make();
    }
}
