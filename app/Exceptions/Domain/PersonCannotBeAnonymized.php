<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use InvalidArgumentException;

/**
 * La persona tiene una cuenta que no se puede anonimizar desde una organización (plan 014, F4): la
 * propia de quien lo pide o la de un usuario de plataforma. La operación se rechaza completa: no cambia nada.
 * (El último administrador activo se rechaza con `LastOrganizationAdmin`.)
 */
final class PersonCannotBeAnonymized extends InvalidArgumentException
{
    public const CODE = 'PERSON_CANNOT_BE_ANONYMIZED';

    public const OWN_ACCOUNT = 'own_account';

    public const PLATFORM_ACCOUNT = 'platform_account';

    private function __construct(public readonly string $reason)
    {
        parent::__construct("No se puede anonimizar a esta persona: {$reason} (código ".self::CODE.').');
    }

    public static function ownAccount(): self
    {
        return new self(self::OWN_ACCOUNT);
    }

    public static function platformAccount(): self
    {
        return new self(self::PLATFORM_ACCOUNT);
    }
}
