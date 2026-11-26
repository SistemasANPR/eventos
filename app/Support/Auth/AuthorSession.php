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
    public const VIA_LINK = 'link';

    public const VIA_SUBMIT = 'submit';

    private const KEY = 'author_session';

    public function __construct(private readonly Session $session) {}

    /**
     * Abre la sesión del autor para esa propuesta, sustituyendo cualquier otra anterior. `$via` deja
     * constancia de cómo se abrió: `link` (enlace de acceso) o `submit` (al enviar la propuesta, plan 034).
     */
    public function open(Submission $submission, string $via = self::VIA_LINK): void
    {
        $this->session->regenerate();
        $this->session->regenerateToken();
        $this->session->put(self::KEY, [
            'submission_id' => $submission->id,
            'organization_id' => $submission->organization_id,
            'expires_at' => Date::now()->addMinutes((int) config('submissions.author.session_minutes'))->getTimestamp(),
        ]);

        $this->audit($submission, 'author_session_opened', ['via' => $via]);
    }

    /** Cierra la sesión a petición del autor y lo deja auditado (plan 034, DA-5). */
    public function logout(): void
    {
        $identity = $this->current();

        if ($identity instanceof AuthorIdentity) {
            $submission = Submission::query()->find($identity->submissionId);

            if ($submission instanceof Submission) {
                $this->audit($submission, 'author_session_closed');
            }
        }

        $this->close();
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

    /**
     * Auditoría sin datos personales: solo el tipo de evento, la organización y, al abrir, cómo se abrió.
     *
     * @param  array<string, string>  $extra
     */
    private function audit(Submission $submission, string $event, array $extra = []): void
    {
        activity()
            ->performedOn($submission)
            ->event($event)
            ->withProperties(['organization_id' => $submission->organization_id, ...$extra])
            ->log($event);
    }
}
