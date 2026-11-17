<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Event;
use App\Models\Rubric;
use App\Models\User;
use App\Support\Auth\ChecksOrganizationPermissions;

/**
 * Quién puede qué con las rúbricas (plan 011, H-011-1): todo se hace con
 * `rubrics.manage`. Exige membresía activa en la organización en contexto y que
 * la rúbrica o el evento sean de ella. El evaluador la leerá por la Policy de
 * sus asignaciones (F4), no por esta.
 */
final class RubricPolicy
{
    use ChecksOrganizationPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, ['rubrics.manage']);
    }

    public function view(User $user, Rubric $rubric): bool
    {
        return $this->allows($user, ['rubrics.manage'], $rubric->organization_id);
    }

    public function create(User $user, Event $event): bool
    {
        return $this->allows($user, ['rubrics.manage'], $event->organization_id);
    }

    public function update(User $user, Rubric $rubric): bool
    {
        return $this->allows($user, ['rubrics.manage'], $rubric->organization_id);
    }

    public function publish(User $user, Rubric $rubric): bool
    {
        return $this->allows($user, ['rubrics.manage'], $rubric->organization_id);
    }
}
