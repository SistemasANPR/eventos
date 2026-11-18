<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Models\Submission;
use App\Models\SubmissionAccessToken;
use App\Support\Submissions\IssuedAccessToken;
use Illuminate\Support\Facades\Date;

/**
 * Crea un enlace de acceso de un solo uso para el autor de una propuesta (plan 006, DA-3,
 * CA-006-30 y CA-006-33). El token son 256 bits de `random_bytes` en base64 seguro para URL;
 * en la base solo se guarda su hash SHA-256. Caduca a los minutos de `submissions.author.access_link_minutes`
 * (30 por defecto). Exige el contexto de organización: el token nace en la de la propuesta.
 */
final class CreateAccessToken
{
    private const TOKEN_BYTES = 32;

    public function handle(Submission $submission): IssuedAccessToken
    {
        $plain = rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
        $expiresAt = Date::now()->addMinutes((int) config('submissions.author.access_link_minutes'));

        $token = new SubmissionAccessToken;
        $token->forceFill([
            'submission_id' => $submission->id,
            'token_hash' => self::hash($plain),
            'expires_at' => $expiresAt,
            'consumed_at' => null,
        ])->save();

        // Sin el token ni el correo: solo qué propuesta y cuándo caduca.
        activity()
            ->performedOn($submission)
            ->event('access_link_created')
            ->withProperties(['organization_id' => $submission->organization_id, 'expires_at' => $expiresAt->toIso8601String()])
            ->log('access_link_created');

        return new IssuedAccessToken($token->id, $plain, $expiresAt);
    }

    /** Huella con la que se guarda y se busca un token. */
    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
