<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Event;
use App\Models\SpeakerCategory;
use App\Models\User;
use App\Support\Auth\ChecksOrganizationPermissions;

/**
 * Quién puede qué con las categorías de ponentes (plan 021, DA-13, CA-021-15): consultar, crear y
 * editar con `speakers.manage` (`org_admin` y `organizer`); eliminar con `speakers.delete` (solo
 * `org_admin`). Exige membresía activa en la organización de la categoría o del evento.
 */
final class SpeakerCategoryPolicy
{
    use ChecksOrganizationPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, ['speakers.manage']);
    }

    public function view(User $user, SpeakerCategory $category): bool
    {
        return $this->allows($user, ['speakers.manage'], $category->organization_id);
    }

    public function create(User $user, Event $event): bool
    {
        return $this->allows($user, ['speakers.manage'], $event->organization_id);
    }

    public function update(User $user, SpeakerCategory $category): bool
    {
        return $this->allows($user, ['speakers.manage'], $category->organization_id);
    }

    public function delete(User $user, SpeakerCategory $category): bool
    {
        return $this->allows($user, ['speakers.delete'], $category->organization_id);
    }
}
