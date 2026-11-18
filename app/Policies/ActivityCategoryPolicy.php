<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ActivityCategory;
use App\Models\Event;
use App\Models\User;
use App\Support\Auth\ChecksOrganizationPermissions;

/**
 * Quién puede qué con las categorías de actividades (plan 021, DA-13, CA-021-15): consultar, crear
 * y editar con `activities.manage` (`org_admin` y `organizer`); eliminar con `activities.delete`
 * (solo `org_admin`). Exige membresía activa en la organización de la categoría o del evento.
 */
final class ActivityCategoryPolicy
{
    use ChecksOrganizationPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, ['activities.manage']);
    }

    public function view(User $user, ActivityCategory $category): bool
    {
        return $this->allows($user, ['activities.manage'], $category->organization_id);
    }

    public function create(User $user, Event $event): bool
    {
        return $this->allows($user, ['activities.manage'], $event->organization_id);
    }

    public function update(User $user, ActivityCategory $category): bool
    {
        return $this->allows($user, ['activities.manage'], $category->organization_id);
    }

    public function delete(User $user, ActivityCategory $category): bool
    {
        return $this->allows($user, ['activities.delete'], $category->organization_id);
    }
}
