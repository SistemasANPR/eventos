<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Enums\OrganizationRole;

/**
 * Matriz rol × permiso de una organización (plan 001 §4, DA-7). Es la única
 * definición; la siembra `SyncOrganizationRoles` y la prueba compara contra la
 * tabla del plan.
 *
 * El `reviewer` (R1) no tiene permisos de organizador: los suyos llegan con el
 * plan 011. El usuario de plataforma no recibe permisos de organización.
 */
final class RolePermissions
{
    /** Permisos del organizador; el administrador los tiene todos y algunos más. */
    private const ORGANIZER = [
        'events.manage',
        'calls.manage',
        'calls.view',
        'tracks.manage',
        'forms.manage',
        'submissions.view',
        'submissions.export',
        'submissions.invite',
        'files.download',
        'mail-templates.view',
    ];

    private const ADMIN_ONLY = [
        'events.publish',
        'privacy_notices.manage',
        'team.manage',
        'mail-templates.manage',
        'personal_data.manage',
    ];

    /** @return array<string, list<string>> permisos por nombre de rol */
    public static function matrix(): array
    {
        return [
            OrganizationRole::OrgAdmin->value => [...self::ORGANIZER, ...self::ADMIN_ONLY],
            OrganizationRole::Organizer->value => self::ORGANIZER,
            OrganizationRole::Reviewer->value => [],
        ];
    }

    /** @return list<string> */
    public static function permissions(): array
    {
        return [...self::ORGANIZER, ...self::ADMIN_ONLY];
    }
}
