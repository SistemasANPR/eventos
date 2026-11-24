<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Tenancy\CurrentOrganization;
use App\Support\Tenancy\PlatformAccess;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Modo opcional para ejercitar TODA la suite bajo RLS (plan 014): con `RLS_TEST_MODE=1`, cada consulta
 * que se ejecuta con una organización en contexto (o con la lectura de plataforma abierta) corre con el
 * rol de aplicación (sin BYPASSRLS, no propietario), y las que se ejecutan sin contexto (fixtures,
 * migraciones, consola) con el rol de las pruebas. Así un flujo real que toque filas de otra organización
 * o que consulte sin contexto algo que RLS protege falla en la prueba, igual que fallaría en producción.
 *
 * No se usa en la corrida normal: es una herramienta de verificación previa a activar RLS en un entorno.
 */
final class RlsTestMode
{
    private static ?bool $actingAsApp = null;

    public static function enabled(): bool
    {
        return getenv('RLS_TEST_MODE') === '1';
    }

    public static function install(): void
    {
        // La transacción de la prueba (o un punto de guardado) deshecha revierte `SET ROLE`: se vuelve a fijar.
        self::$actingAsApp = null;
        AppDatabaseRole::provision();

        Event::listen(TransactionRolledBack::class, static function (): void {
            self::$actingAsApp = null;
        });

        DB::connection()->beforeExecuting(static function (string $query, array $bindings, Connection $connection): void {
            $wanted = CurrentOrganization::has() || PlatformAccess::isEnabled();

            if ($wanted === self::$actingAsApp || $connection->pretending()) {
                return;
            }

            $connection->getPdo()->exec($wanted ? 'SET ROLE '.AppDatabaseRole::NAME : 'RESET ROLE');
            self::$actingAsApp = $wanted;
        });
    }
}
