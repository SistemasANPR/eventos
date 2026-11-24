<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Único lugar que salta el scope de organización (ADR-0003, acción 10 de
 * AGENTS.md; la guardiana `TenantBypassTest` lo exige). Solo un usuario de
 * plataforma autenticado puede hacerlo y cada uso deja un registro de
 * auditoría con quién, qué modelo y desde qué panel (nulo fuera de un panel).
 */
final class OrganizationScopeBypass
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     *
     * @throws AuthorizationException si no hay un usuario de plataforma autenticado
     */
    public static function apply(Builder $query): void
    {
        $user = Auth::user();

        if (! $user instanceof User || ! $user->is_platform_admin) {
            throw new AuthorizationException;
        }

        activity()
            ->causedBy($user)
            ->event('organization_scope_bypassed')
            ->withProperties(['model' => $query->getModel()::class, 'panel' => Filament::getCurrentPanel()?->getId()])
            ->log('organization_scope_bypassed');

        // Segunda barrera (plan 014): con RLS activa, saltar el scope no basta para leer otras
        // organizaciones; el mismo camino autorizado y auditado abre la lectura de plataforma en la base
        // y la cierra al terminar la petición (el trabajador de colas la cierra entre jobs).
        PlatformAccess::enable();
        app()->terminating(static fn () => PlatformAccess::disable());

        $query->withoutGlobalScope(OrganizationScope::class);
    }
}
