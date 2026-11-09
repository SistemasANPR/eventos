<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Organization;
use App\Support\Tenancy\Exceptions\MissingOrganizationContext;

/**
 * Organización activa del proceso (ADR-0003).
 *
 * El contexto es estado del proceso: quien lo establece (el middleware, y más
 * adelante jobs y comandos) es responsable de limpiarlo con `forget()`.
 */
final class CurrentOrganization
{
    private static ?Organization $organization = null;

    public static function set(Organization $organization): void
    {
        self::$organization = $organization;
    }

    public static function has(): bool
    {
        return self::$organization !== null;
    }

    /** @throws MissingOrganizationContext */
    public static function get(): Organization
    {
        return self::$organization ?? throw new MissingOrganizationContext;
    }

    /** @throws MissingOrganizationContext */
    public static function id(): string
    {
        return self::get()->id;
    }

    public static function forget(): void
    {
        self::$organization = null;
    }
}
