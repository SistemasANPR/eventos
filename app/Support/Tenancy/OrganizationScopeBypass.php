<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Único lugar que salta el scope de organización (ADR-0003, acción 10 de
 * AGENTS.md; la guardiana `TenantBypassTest` lo exige). Solo un usuario de
 * plataforma autenticado puede hacerlo y cada uso deja un registro de
 * auditoría con quién y qué modelo.
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
            ->withProperties(['model' => $query->getModel()::class])
            ->log('organization_scope_bypassed');

        $query->withoutGlobalScope(OrganizationScope::class);
    }
}
