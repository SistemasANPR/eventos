<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Models\CallForSubmissions;
use Illuminate\Support\Str;

/**
 * Claves de idempotencia que el servidor emite al renderizar el formulario público
 * (plan 006, DA-9, CA-006-14 y CA-006-15). Solo se guarda en la sesión el hash (sha256)
 * de cada clave, ligado a la convocatoria. Una clave no se consume al usarla: un
 * reintento con la misma clave debe poder recibir la misma confirmación.
 */
final class IdempotencyKeys
{
    /** Tope de claves vivas por convocatoria y sesión: evita crecer sin límite al recargar el formulario. */
    private const MAX_PER_CALL = 20;

    public function issue(CallForSubmissions $call): string
    {
        $key = (string) Str::uuid();

        $hashes = $this->hashes($call);
        $hashes[] = hash('sha256', $key);

        session()->put($this->sessionKey($call), array_slice($hashes, -self::MAX_PER_CALL));

        return $key;
    }

    public function has(CallForSubmissions $call, string $key): bool
    {
        if ($key === '') {
            return false;
        }

        $candidate = hash('sha256', $key);

        foreach ($this->hashes($call) as $hash) {
            if (hash_equals($hash, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function hashes(CallForSubmissions $call): array
    {
        $stored = session()->get($this->sessionKey($call), []);

        return is_array($stored) ? array_values(array_filter($stored, 'is_string')) : [];
    }

    private function sessionKey(CallForSubmissions $call): string
    {
        return 'idempotency_keys.'.$call->id;
    }
}
