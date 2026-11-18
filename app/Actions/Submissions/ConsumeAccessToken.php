<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Exceptions\Domain\AccessLinkInvalid;
use App\Models\Event;
use App\Models\Submission;
use App\Models\SubmissionAccessToken;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use SensitiveParameter;

/**
 * Consume un enlace de acceso del autor (plan 006, DA-3, CA-006-33, CA-006-35 y CA-006-36) y devuelve la
 * propuesta a la que da acceso. Abrir la sesión es de `AuthorSession`.
 *
 * El token se busca por su hash SHA-256 DENTRO de la organización en contexto (la de la ruta): el de
 * otra organización no existe aquí. El consumo es atómico: un solo
 * `UPDATE ... WHERE consumed_at IS NULL AND expires_at > ahora`, de modo que de dos peticiones
 * simultáneas con el mismo token solo una afecta la fila. Consumido, caducado, alterado, inexistente,
 * de otra organización o de otro evento: siempre `AccessLinkInvalid`, sin distinguir la causa.
 *
 * @throws AccessLinkInvalid
 */
final class ConsumeAccessToken
{
    private const FORMAT = '/^[A-Za-z0-9_-]{16,128}$/';

    public function handle(Event $event, #[SensitiveParameter] string $token): Submission
    {
        if (preg_match(self::FORMAT, $token) !== 1) {
            throw new AccessLinkInvalid;
        }

        $hash = CreateAccessToken::hash($token);
        $stored = SubmissionAccessToken::query()->where('token_hash', $hash)->first();

        // Defensa en profundidad: la búsqueda es por igualdad en la base; esta comparación es en tiempo constante.
        if ($stored === null || ! hash_equals($stored->token_hash, $hash)) {
            throw new AccessLinkInvalid;
        }

        $now = Date::now();
        $consumed = SubmissionAccessToken::query()
            ->whereKey($stored->id)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', $now)
            ->whereHas('submission', fn (Builder $submissions) => $submissions->where('event_id', $event->id))
            ->update(['consumed_at' => $now]);

        if ($consumed !== 1) {
            throw new AccessLinkInvalid;
        }

        $submission = Submission::query()->where('event_id', $event->id)->find($stored->submission_id);

        if (! $submission instanceof Submission) {
            throw new AccessLinkInvalid;
        }

        activity()
            ->performedOn($submission)
            ->event('access_link_consumed')
            ->withProperties(['organization_id' => $submission->organization_id])
            ->log('access_link_consumed');

        return $submission;
    }
}
