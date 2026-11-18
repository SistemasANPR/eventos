<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Quién es el autor con sesión (guard `author`): no una persona con cuenta, sino el acceso a UNA
 * propuesta de una organización (plan 006, DA-3). Su identificador es el de la propuesta. No tiene
 * contraseña ni se puede recordar: la sesión se abre solo con un enlace de un solo uso.
 */
final readonly class AuthorIdentity implements Authenticatable
{
    public function __construct(public string $submissionId, public string $organizationId) {}

    public function getAuthIdentifierName(): string
    {
        return 'submission_id';
    }

    public function getAuthIdentifier(): string
    {
        return $this->submissionId;
    }

    public function getAuthPasswordName(): string
    {
        return '';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
        // Sin «recordarme»: la sesión del autor siempre caduca.
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}
