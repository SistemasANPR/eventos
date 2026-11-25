<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDOException;

/**
 * Rol de aplicación de PostgreSQL para probar RLS (plan 014, H-014-2).
 *
 * Las pruebas se conectan con un usuario con privilegios amplios (superusuario
 * en el desarrollo local y en CI), que ignora RLS. Para ver el efecto real de
 * las políticas, `run()` cambia la sesión a un rol **sin `BYPASSRLS`, sin ser
 * propietario de las tablas**, justo lo que se pide al rol de la aplicación en
 * staging y producción, y lo restaura al terminar.
 *
 * El rol (`NOLOGIN`, solo para `SET ROLE`) es del clúster: se crea una vez con
 * una conexión aparte (fuera de la transacción de la prueba) y se le conceden
 * los permisos de DML sobre las tablas que existan en ese momento. No tiene
 * contraseña y no puede iniciar sesión; no hace falta borrarlo.
 */
final class AppDatabaseRole
{
    public const NAME = 'eventos_rls_test_app';

    private static bool $provisioned = false;

    /**
     * Ejecuta `$callback` con la sesión actuando como el rol de aplicación.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function run(Closure $callback): mixed
    {
        self::provision();

        DB::statement('SET ROLE '.self::NAME);

        try {
            // Punto de guardado: una escritura rechazada por RLS aborta la transacción de la prueba
            // (RefreshDatabase) y sin él ni siquiera podría restaurarse el rol.
            return DB::transaction($callback);
        } finally {
            DB::statement('RESET ROLE');
        }
    }

    /** Una conexión nueva a la misma base, con su propia sesión y sin la transacción de la prueba. */
    public static function separateConnection(): Connection
    {
        $connection = DB::build(config('database.connections.'.config('database.default')));

        return $connection instanceof Connection ? $connection : throw new LogicException('DB::build no devolvió una conexión de base de datos.');
    }

    public static function provision(): void
    {
        if (self::$provisioned) {
            return;
        }

        // Conexión aparte: la prueba corre dentro de una transacción que se revierte y los permisos deben persistir.
        $admin = self::separateConnection();
        $role = self::NAME;

        try {
            $admin->unprepared("DO \$\$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{$role}') THEN CREATE ROLE {$role} NOLOGIN NOSUPERUSER NOBYPASSRLS NOINHERIT; END IF; END \$\$");
        } catch (PDOException $exception) {
            // Otra corrida lo creó a la vez (duplicate_object): basta con que exista.
            if (! str_contains($exception->getMessage(), 'already exists')) {
                throw $exception;
            }
        }

        $admin->unprepared("GRANT USAGE ON SCHEMA public TO {$role}");
        $admin->unprepared("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO {$role}");
        $admin->unprepared("GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO {$role}");
        $admin->disconnect();

        self::$provisioned = true;
    }
}
