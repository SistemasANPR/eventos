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
        'rubrics.manage',
        'forms.manage',
        'submissions.view',
        'submissions.export',
        'submissions.decide',
        'files.download',
        'mail-templates.view',
        'speakers.manage',
        'activities.manage',
        'lodgings.manage',
        'sponsors.manage',
        'review_assignments.manage',
        'reviewers.invite',
        'reviews.view_results',
        'reviews.view_progress',
        'reviews.close_period',
    ];

    private const ADMIN_ONLY = [
        'events.publish',
        'event_assignments.manage',
        'privacy_notices.manage',
        'team.manage',
        'mail-templates.manage',
        'personal_data.manage',
        'speakers.delete',
        'activities.delete',
        'lodgings.delete',
        'sponsors.delete',
        'two_factor.reset',
        'submissions.revert',
        'retention.manage',
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
