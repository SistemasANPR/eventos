<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PDO;
use WeakMap;

/**
 * Contexto de organización en la base de datos (plan 014, DA-2): las políticas de RLS leen la
 * variable de sesión `app.organization_id` (y `app.platform_access`), y este objeto garantiza que
 * **antes de cada consulta** valga lo mismo que `CurrentOrganization` en el proceso.
 *
 * Se sincroniza al ejecutar, no al cambiar el contexto, y eso da las garantías del contrato:
 *
 * - Un solo punto: petición HTTP (Filament, Livewire), job, comando y consola pasan por aquí
 *   porque todos establecen el contexto con `CurrentOrganization`, sin que cada uno repita nada.
 * - No se queda pegado: al olvidar el contexto, la siguiente consulta limpia la variable aunque
 *   la conexión se reutilice (trabajadores de cola, procesos largos, conexiones persistentes).
 *   Una conexión nueva o reconectada empieza sin valor y recibe el actual antes de su primera consulta.
 * - Un `ROLLBACK` revierte los `SET` hechos dentro de la transacción (también de un punto de
 *   guardado): el registro de lo aplicado se invalida y la siguiente consulta vuelve a fijarlo.
 * - Sin base de datos no hace nada: cambiar el contexto no abre conexión.
 *
 * Se usa un `set_config` de sesión y no `SET LOCAL` (que pide transacción): el middleware de
 * tenant de Filament fija el contexto sin envolver la petición, y abrir una transacción por
 * petición cambiaría la semántica de `afterCommit` y de los reintentos. El límite lo cubren las
 * garantías anteriores. Requiere conexiones directas o un pooler en modo sesión (no por transacción).
 */
final class DatabaseTenantContext
{
    public const ORGANIZATION_SETTING = 'app.organization_id';

    public const PLATFORM_SETTING = 'app.platform_access';

    /** @var WeakMap<PDO, string>|null lo último que se fijó en cada conexión física */
    private static ?WeakMap $applied = null;

    /** @var WeakMap<Connection, true>|null */
    private static ?WeakMap $hooked = null;

    /** Engancha la sincronización a toda conexión PostgreSQL, las ya abiertas y las futuras. */
    public static function register(): void
    {
        Event::listen(ConnectionEstablished::class, static fn (ConnectionEstablished $event) => self::hook($event->connection));

        // Una transacción deshecha revierte los SET de su interior: lo aplicado deja de ser fiable.
        Event::listen(TransactionRolledBack::class, static function (TransactionRolledBack $event): void {
            $pdo = $event->connection->getRawPdo();

            if ($pdo instanceof PDO) {
                unset(self::applied()[$pdo]);
            }
        });

        foreach (DB::getConnections() as $connection) {
            self::hook($connection);
        }
    }

    /** Fija en la sesión el contexto actual si difiere de lo ya aplicado a esa conexión. */
    public static function sync(Connection $connection): void
    {
        if ($connection->getDriverName() !== 'pgsql' || $connection->pretending()) {
            return;
        }

        // Una conexión cerrada (`disconnect`) se reabre después de este gancho: se reabre antes para fijarle el contexto.
        if ($connection->getRawPdo() === null) {
            $connection->reconnect();
        }

        $pdo = $connection->getPdo();
        $organization = CurrentOrganization::has() ? CurrentOrganization::id() : '';
        $platform = PlatformAccess::isEnabled() ? 'on' : '';
        $state = $organization.'|'.$platform;

        if ((self::applied()[$pdo] ?? null) === $state) {
            return;
        }

        $statement = $pdo->prepare('select set_config(?::text, ?::text, false), set_config(?::text, ?::text, false)');
        $statement->execute([self::ORGANIZATION_SETTING, $organization, self::PLATFORM_SETTING, $platform]);

        self::applied()[$pdo] = $state;
    }

    private static function hook(Connection $connection): void
    {
        self::$hooked ??= new WeakMap;

        if (isset(self::$hooked[$connection])) {
            return;
        }

        self::$hooked[$connection] = true;
        $connection->beforeExecuting(static fn (string $query, array $bindings, Connection $executing) => self::sync($executing));
    }

    /** @return WeakMap<PDO, string> */
    private static function applied(): WeakMap
    {
        return self::$applied ??= new WeakMap;
    }
}
