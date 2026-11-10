<?php

declare(strict_types=1);

namespace App\Filament\Platform\Support;

use App\Enums\OrganizationRole;
use App\Models\Activity;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;

/**
 * Consultas del panel de plataforma que cruzan organizaciones (plan 016). Es el
 * único sitio del panel que toca un modelo con organización: lo hace con
 * `withoutOrganizationScope()`, que audita cada uso, y solo para metadatos (conteos).
 */
final class PlatformQueries
{
    /**
     * Subconsulta con el número de eventos (no eliminados) de cada organización de la lista.
     *
     * @return Builder<Event>
     */
    public static function eventsOfOrganization(): Builder
    {
        return Event::query()
            ->withoutOrganizationScope()
            ->selectRaw('count(*)')
            ->whereColumn('events.organization_id', 'organizations.id');
    }

    /**
     * Administradores activos de una organización, como «Nombre <correo>».
     *
     * @return list<string>
     */
    public static function administratorsOf(Organization $organization): array
    {
        // Los roles se consultan por *team*: hace falta la organización en contexto.
        return CurrentOrganization::run($organization, fn (): array => User::query()
            ->activeMembersOf($organization)
            ->role(OrganizationRole::OrgAdmin->value)
            ->orderBy('name')
            ->get()
            ->map(fn (User $user): string => "{$user->name} <{$user->email}>")
            ->all());
    }

    /**
     * Membresías de un usuario como «Organización · rol1, rol2 · estado».
     *
     * @return list<string>
     */
    public static function membershipsOf(User $user): array
    {
        return $user->organizations()->orderBy('name')->get()
            ->map(function (Organization $organization) use ($user): string {
                // Los roles se consultan por *team*: se lee con la organización en contexto.
                $roles = CurrentOrganization::run($organization, fn (): string => $user->unsetRelation('roles')->getRoleNames()->sort()->implode(', '));

                return implode(' · ', array_filter([
                    $organization->name,
                    $roles,
                    (string) $organization->getRelation('pivot')->getAttribute('status'),
                ]));
            })
            ->all();
    }

    /**
     * Últimos registros de auditoría de la organización: fecha (UTC), evento y actor.
     *
     * @return list<string>
     */
    public static function recentAuditOf(Organization $organization, int $limit = 10): array
    {
        return Activity::query()
            ->where('organization_id', $organization->id)
            ->with('causer')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (Activity $entry): string => implode(' · ', array_filter([
                $entry->created_at?->utc()->format('Y-m-d H:i').' UTC',
                $entry->event ?? $entry->description,
                $entry->causer instanceof User ? $entry->causer->name : null,
            ])))
            ->all();
    }
}
