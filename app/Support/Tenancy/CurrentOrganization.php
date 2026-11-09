<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Organization;
use App\Support\Tenancy\Exceptions\MissingOrganizationContext;
use Closure;
use Spatie\Permission\PermissionRegistrar;

/**
 * Organización activa del proceso (ADR-0003).
 *
 * El contexto es estado del proceso: quien lo establece (el middleware, y más
 * adelante jobs y comandos) es responsable de limpiarlo, preferentemente con
 * `run()`, que restaura el contexto anterior.
 *
 * Es también la única fuente del *team* de `spatie/laravel-permission`: cada
 * cambio de contexto lo propaga, para que los roles y permisos que se
 * consultan sean siempre los de la organización en contexto.
 */
final class CurrentOrganization
{
    private static ?Organization $organization = null;

    public static function set(Organization $organization): void
    {
        self::$organization = $organization;
        self::syncPermissionsTeam();
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
        self::syncPermissionsTeam();
    }

    /**
     * Ejecuta `$callback` con `$organization` en contexto y restaura después el
     * contexto anterior (o ninguno), incluso si el callback lanza una excepción.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function run(Organization $organization, Closure $callback): mixed
    {
        $previous = self::$organization;
        self::set($organization);

        try {
            return $callback();
        } finally {
            $previous === null ? self::forget() : self::set($previous);
        }
    }

    private static function syncPermissionsTeam(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(self::$organization?->id);
    }
}
