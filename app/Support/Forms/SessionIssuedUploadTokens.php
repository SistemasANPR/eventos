<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Models\CallForSubmissions;
use App\Support\Files\UploadToken;
use Illuminate\Support\Str;

/** Guarda en la sesión de Laravel el hash (sha256) de cada token emitido; el token en claro nunca se almacena. */
final class SessionIssuedUploadTokens implements IssuedUploadTokens
{
    /** Tope de tokens vivos por convocatoria y sesión: evita crecer sin límite al recargar el formulario. */
    private const MAX_PER_CALL = 10;

    public function issue(CallForSubmissions $call): string
    {
        $token = Str::random(40);

        $hashes = $this->hashes($call);
        $hashes[] = UploadToken::hash($token);

        session()->put($this->key($call), array_slice($hashes, -self::MAX_PER_CALL));

        return $token;
    }

    public function has(CallForSubmissions $call, string $token): bool
    {
        if ($token === '') {
            return false;
        }

        foreach ($this->hashes($call) as $hash) {
            if (UploadToken::matches($hash, $token)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function hashes(CallForSubmissions $call): array
    {
        $stored = session()->get($this->key($call), []);

        return is_array($stored) ? array_values(array_filter($stored, 'is_string')) : [];
    }

    private function key(CallForSubmissions $call): string
    {
        return 'upload_tokens.'.$call->id;
    }
}
