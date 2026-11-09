<?php

declare(strict_types=1);

namespace App\Enums;

/** Roles de una organización (plan 001 §4). Viven en `spatie/laravel-permission` con la organización como team. */
enum OrganizationRole: string
{
    case OrgAdmin = 'org_admin';
    case Organizer = 'organizer';
    case Reviewer = 'reviewer';
}
