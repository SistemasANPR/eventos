<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * RLS de PostgreSQL como segunda barrera de aislamiento entre organizaciones (plan 014, DA-2).
 *
 * Cada tabla con `organization_id` lleva dos políticas, ambas permisivas:
 *
 * - `tenant_isolation` (todas las órdenes): la fila es visible y modificable si su
 *   `organization_id` es la de `app.organization_id`; sin variable, ninguna fila y
 *   ninguna escritura. `WITH CHECK` impide insertar o mover una fila a otra organización.
 * - `platform_read` (solo `SELECT`): lectura de plataforma, cuando `PlatformAccess` está
 *   activo (`OrganizationScopeBypass`, con auditoría). No concede escribir.
 *
 * Se habilita RLS **sin `FORCE`**: el propietario de las tablas (el rol de migraciones) no
 * depende de ella, y el rol de la aplicación, que no es propietario ni tiene `BYPASSRLS`,
 * queda sujeto. Mientras la aplicación se conecte con un rol que ignora RLS (superusuario
 * en local, propietario en un despliegue sin separar roles), las políticas existen pero no
 * tienen efecto: la activación es conectarla con el rol de aplicación (`docs/operacion.md` §10).
 *
 * Para proteger una tabla nueva basta una línea en su migración, después de crearla:
 * `RowLevelSecurity::enable('mi_tabla');`. La guardiana `RowLevelSecurityGuardianTest` falla si una
 * tabla con `organization_id` no lo hace ni figura en `PLATFORM_TABLES`.
 */
final class RowLevelSecurity
{
    public const POLICY = 'tenant_isolation';

    public const PLATFORM_READ_POLICY = 'platform_read';

    /**
     * Lista cerrada: tablas con `organization_id` que quedan fuera de RLS. Añadir una es una
     * decisión de seguridad (acción 10 de AGENTS.md): justificarla aquí y en el pull request.
     * Su aislamiento lo dan las Policies, el *team* de permisos y la autenticación.
     *
     * @var array<string, string>
     */
    public const PLATFORM_TABLES = [
        'organization_user' => 'Membresías: el inicio de sesión y el selector de organización las leen sin ninguna organización en contexto, para saber a cuáles pertenece una persona (DA-3 del ADR-0003). Su acceso lo gobiernan las Policies.',
        'roles' => 'Roles de spatie con la organización como team (y roles globales con organización nula): se consultan sin contexto para decidir el panel y la exigencia de 2FA de una persona (`User::requiresTwoFactor`). El team los acota.',
        'model_has_roles' => 'Asignación de roles de spatie con la organización como team: se lee sin contexto al iniciar sesión y al decidir el panel o la 2FA de una persona. El team los acota.',
        'model_has_permissions' => 'Permisos directos de spatie con la organización como team: mismas razones que `model_has_roles`; hoy no se asignan permisos directos, solo roles.',
        'activity_log' => 'Bitácora de plataforma: `organization_id` es nulo para los hechos de plataforma y se escribe desde cualquier contexto, también sin él (inicio de sesión, consola); solo la lee el panel de plataforma. Acotarla por organización es la deuda DT-46.',
    ];

    /** Habilita RLS en la tabla y crea sus políticas. Idempotente. No hace nada fuera de PostgreSQL. */
    public static function enable(string $table): void
    {
        if (! self::isPostgres()) {
            return;
        }

        $quoted = self::quote($table);
        self::ensureFunctions();

        DB::unprepared("ALTER TABLE {$quoted} ENABLE ROW LEVEL SECURITY");
        DB::unprepared('DROP POLICY IF EXISTS '.self::POLICY." ON {$quoted}");
        DB::unprepared('CREATE POLICY '.self::POLICY." ON {$quoted} USING (organization_id = app_current_organization_id()) WITH CHECK (organization_id = app_current_organization_id())");
        DB::unprepared('DROP POLICY IF EXISTS '.self::PLATFORM_READ_POLICY." ON {$quoted}");
        DB::unprepared('CREATE POLICY '.self::PLATFORM_READ_POLICY." ON {$quoted} FOR SELECT USING (app_platform_access())");
    }

    /** Quita las políticas y deshabilita RLS en la tabla. Idempotente. No borra datos. */
    public static function disable(string $table): void
    {
        if (! self::isPostgres()) {
            return;
        }

        $quoted = self::quote($table);

        DB::unprepared('DROP POLICY IF EXISTS '.self::PLATFORM_READ_POLICY." ON {$quoted}");
        DB::unprepared('DROP POLICY IF EXISTS '.self::POLICY." ON {$quoted}");
        DB::unprepared("ALTER TABLE {$quoted} DISABLE ROW LEVEL SECURITY");
    }

    /**
     * Protege toda tabla con `organization_id` que aún no lo esté y no sea de la lista cerrada.
     * Es lo que hace la migración de RLS y la que sirve de «puesta al día» si otra rama añadió tablas.
     *
     * @return list<string> las tablas que se protegieron
     */
    public static function enableMissing(): array
    {
        $missing = self::unprotectedTables();

        foreach ($missing as $table) {
            self::enable($table);
        }

        return $missing;
    }

    /** Quita RLS de todas las tablas protegidas y las funciones de apoyo (reversa completa, sin pérdida de datos). */
    public static function disableAll(): void
    {
        if (! self::isPostgres()) {
            return;
        }

        foreach (self::protectedTables() as $table) {
            self::disable($table);
        }

        DB::unprepared('DROP FUNCTION IF EXISTS app_current_organization_id()');
        DB::unprepared('DROP FUNCTION IF EXISTS app_platform_access()');
    }

    /**
     * Tablas con RLS habilitado y la política de aislamiento.
     *
     * @return list<string>
     */
    public static function protectedTables(): array
    {
        return array_map(static fn (object $row): string => $row->relname, DB::select(
            'select c.relname from pg_class c join pg_namespace n on n.oid = c.relnamespace
             where n.nspname = current_schema() and c.relkind = \'r\' and c.relrowsecurity
               and exists (select 1 from pg_policies p where p.schemaname = n.nspname and p.tablename = c.relname and p.policyname = ?)
             order by c.relname',
            [self::POLICY],
        ));
    }

    /**
     * Tablas con `organization_id` sin RLS ni política y fuera de la lista cerrada: lo que la guardiana prohíbe.
     *
     * @return list<string>
     */
    public static function unprotectedTables(): array
    {
        $rows = DB::select(
            'select t.table_name from information_schema.tables t
             join information_schema.columns c on c.table_schema = t.table_schema and c.table_name = t.table_name and c.column_name = \'organization_id\'
             where t.table_schema = current_schema() and t.table_type = \'BASE TABLE\'
             order by t.table_name',
        );

        $withOrganization = array_map(static fn (object $row): string => $row->table_name, $rows);

        return array_values(array_diff($withOrganization, array_keys(self::PLATFORM_TABLES), self::protectedTables()));
    }

    /**
     * Estado de la protección para el rol con el que está conectada la sesión: sirve para comprobar,
     * antes y después de activar RLS, que de verdad tiene efecto (`app:rls-status`).
     *
     * @return array{role: string, role_is_superuser: bool, role_has_bypassrls: bool, owns_protected_tables: bool, role_bypasses_rls: bool, effective: bool, protected_tables: list<string>, unprotected_tables: list<string>}
     */
    public static function status(): array
    {
        $role = DB::selectOne('select current_user as name, r.rolsuper, r.rolbypassrls from pg_roles r where r.rolname = current_user');
        $owns = (int) DB::selectOne(
            'select count(*) as total from pg_class c join pg_namespace n on n.oid = c.relnamespace
             where n.nspname = current_schema() and c.relkind = \'r\' and c.relrowsecurity and not c.relforcerowsecurity
               and pg_has_role(current_user, c.relowner, \'USAGE\')',
        )->total > 0;

        $protected = self::protectedTables();
        $superuser = (bool) $role->rolsuper;
        $bypass = (bool) $role->rolbypassrls;
        $bypasses = $superuser || $bypass || $owns;

        return [
            'role' => (string) $role->name,
            'role_is_superuser' => $superuser,
            'role_has_bypassrls' => $bypass,
            'owns_protected_tables' => $owns,
            'role_bypasses_rls' => $bypasses,
            'effective' => $protected !== [] && ! $bypasses,
            'protected_tables' => $protected,
            'unprotected_tables' => self::unprotectedTables(),
        ];
    }

    private static function ensureFunctions(): void
    {
        // Lo que dice la sesión: la organización activa (vacía = ninguna) y la marca de lectura de plataforma.
        DB::unprepared("CREATE OR REPLACE FUNCTION app_current_organization_id() RETURNS uuid LANGUAGE sql STABLE AS \$\$ SELECT nullif(current_setting('".DatabaseTenantContext::ORGANIZATION_SETTING."', true), '')::uuid \$\$");
        DB::unprepared("CREATE OR REPLACE FUNCTION app_platform_access() RETURNS boolean LANGUAGE sql STABLE AS \$\$ SELECT coalesce(current_setting('".DatabaseTenantContext::PLATFORM_SETTING."', true), '') = 'on' \$\$");
    }

    private static function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    private static function quote(string $table): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $table) !== 1) {
            throw new InvalidArgumentException("Nombre de tabla no válido para RLS: {$table}");
        }

        return '"'.$table.'"';
    }
}
