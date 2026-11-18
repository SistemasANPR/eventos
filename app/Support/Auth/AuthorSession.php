<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\Submission;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Date;

/**
 * Sesión del autor de una propuesta, sin cuenta (plan 006, DA-3, CA-006-34, CA-006-37 y CA-006-38).
 *
 * Guarda en la sesión del navegador SOLO el identificador de una propuesta y su organización, con
 * caducidad absoluta (`submissions.author.session_minutes`, 60 por defecto). Al abrirla se regenera el
 * identificador de sesión (contra fijación de sesión). No toca el guard `web` del panel: un usuario del
 * panel no es autor y un autor no entra al panel. Es lo que lee el guard `author` (`AuthorGuard`).
 */
final class AuthorSession
{
    private const KEY = 'author_session';

    public function __construct(private readonly Session $session) {}

    /** Abre la sesión del autor para esa propuesta, sustituyendo cualquier otra anterior. */
    public function open(Submission $submission): void
    {
        $this->session->regenerate();
        $this->session->regenerateToken();
        $this->session->put(self::KEY, [
            'submission_id' => $submission->id,
            'organization_id' => $submission->organization_id,
            'expires_at' => Date::now()->addMinutes((int) config('submissions.author.session_minutes'))->getTimestamp(),
        ]);
    }

    /** La sesión vigente, o `null` si no hay o ya caducó (y entonces se descarta). */
    public function current(): ?AuthorIdentity
    {
        $data = $this->session->get(self::KEY);

        if (! is_array($data)) {
            return null;
        }

        $submissionId = $data['submission_id'] ?? null;
        $organizationId = $data['organization_id'] ?? null;
        $expiresAt = $data['expires_at'] ?? null;

        if (! is_string($submissionId) || ! is_string($organizationId) || ! is_int($expiresAt) || $expiresAt <= Date::now()->getTimestamp()) {
            $this->close();

            return null;
        }

        return new AuthorIdentity($submissionId, $organizationId);
    }

    public function close(): void
    {
        $this->session->forget(self::KEY);
    }
}
