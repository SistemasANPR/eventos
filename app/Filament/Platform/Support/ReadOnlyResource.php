<?php

declare(strict_types=1);

namespace App\Filament\Platform\Support;

use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Base de las pantallas de solo lectura del panel de plataforma (plan 016):
 * ver requiere el Gate `platform.access`; crear, editar y borrar se deniegan
 * siempre. No se apoya en las Policies de los modelos (`MembershipPolicy`,
 * `EventPolicy`), que deciden por la organización en contexto y no deben abrirse
 * a plataforma.
 */
abstract class ReadOnlyResource extends Resource
{
    protected static bool $isScopedToTenant = false;

    public static function canViewAny(): bool
    {
        return Gate::allows('platform.access');
    }

    public static function canView(Model $record): bool
    {
        return Gate::allows('platform.access');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    public static function canRestore(Model $record): bool
    {
        return false;
    }

    public static function canRestoreAny(): bool
    {
        return false;
    }

    public static function canReplicate(Model $record): bool
    {
        return false;
    }
}
