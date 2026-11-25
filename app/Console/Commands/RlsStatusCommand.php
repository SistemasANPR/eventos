<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Console\Command;

/**
 * Informa del estado de RLS para el rol con el que se conecta la aplicación (plan 014, H-014-2) y sirve de
 * comprobación al activarla o revertirla en staging y producción (`docs/operacion.md` §10). No modifica nada.
 */
final class RlsStatusCommand extends Command
{
    protected $signature = 'app:rls-status
        {--require-effective : Falla si el rol de la conexión ignora RLS (superusuario, BYPASSRLS o propietario de las tablas)}';

    protected $description = 'Muestra qué tablas protege RLS y si tiene efecto para el rol de la conexión actual';

    public function handle(): int
    {
        $status = RowLevelSecurity::status();

        $this->line('Rol de la conexión: '.$status['role']);
        $this->line('  superusuario: '.($status['role_is_superuser'] ? 'sí' : 'no'));
        $this->line('  BYPASSRLS: '.($status['role_has_bypassrls'] ? 'sí' : 'no'));
        $this->line('  propietario de tablas con RLS: '.($status['owns_protected_tables'] ? 'sí' : 'no'));
        $this->line('Tablas con RLS y política: '.count($status['protected_tables']));
        $this->line('RLS tiene efecto para este rol: '.($status['effective'] ? 'SÍ' : 'NO'));

        $failed = false;

        if ($status['unprotected_tables'] !== []) {
            $this->error('Tablas con organization_id sin política y fuera de la lista cerrada: '.implode(', ', $status['unprotected_tables']));
            $this->line('Protegerlas con RowLevelSecurity::enable() en una migración (docs/operacion.md §10).');
            $failed = true;
        }

        if ($this->option('require-effective') && ! $status['effective']) {
            $this->error('RLS no tiene efecto con este rol: la aplicación debe conectarse con el rol de aplicación (DB_USERNAME).');
            $failed = true;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
