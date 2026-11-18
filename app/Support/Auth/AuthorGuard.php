<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;

/**
 * Guard `author` (config/auth.php): expone la sesión del autor (`AuthorSession`) con la interfaz
 * estándar, de modo que `Auth::guard('author')->check()` responde si hay un autor con sesión vigente.
 * Es independiente del guard `web`: ninguno de los dos reconoce la sesión del otro (CA-006-37).
 */
final class AuthorGuard implements Guard
{
    public function __construct(private readonly AuthorSession $session) {}

    public function check(): bool
    {
        return $this->session->current() !== null;
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function user(): ?AuthorIdentity
    {
        return $this->session->current();
    }

    public function id(): ?string
    {
        return $this->session->current()?->submissionId;
    }

    /** @param  array<string, mixed>  $credentials */
    public function validate(array $credentials = []): bool
    {
        // No hay credenciales: la sesión solo se abre con un enlace de un solo uso (`ConsumeAccessToken`).
        return false;
    }

    public function hasUser(): bool
    {
        return $this->check();
    }

    public function setUser(Authenticatable $user): static
    {
        // Nadie fija al autor a mano: solo `AuthorSession::open()` abre la sesión.
        return $this;
    }
}
