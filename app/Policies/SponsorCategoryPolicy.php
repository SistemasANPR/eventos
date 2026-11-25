<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Event;
use App\Models\SponsorCategory;
use App\Models\User;
use App\Support\Auth\ChecksOrganizationPermissions;

/**
 * Quién puede qué con las categorías de patrocinador (plan 030, DA-6, CA-030-12): consultar, crear y editar con
 * `sponsors.manage` (`org_admin` y `organizer`); eliminar con `sponsors.delete` (solo `org_admin`). Exige
 * membresía activa en la organización de la categoría o del evento.
 */
final class SponsorCategoryPolicy
{
    use ChecksOrganizationPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, ['sponsors.manage']);
    }

    public function view(User $user, SponsorCategory $category): bool
    {
        return $this->allows($user, ['sponsors.manage'], $category->organization_id);
    }

    public function create(User $user, Event $event): bool
    {
        return $this->allows($user, ['sponsors.manage'], $event->organization_id);
    }

    public function update(User $user, SponsorCategory $category): bool
    {
        return $this->allows($user, ['sponsors.manage'], $category->organization_id);
    }

    public function delete(User $user, SponsorCategory $category): bool
    {
        return $this->allows($user, ['sponsors.delete'], $category->organization_id);
    }
}
