<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Event;
use App\Models\Speaker;
use App\Models\User;
use App\Support\Auth\ChecksOrganizationPermissions;

/**
 * Quién puede qué con los ponentes (plan 021, DA-13, CA-021-15): ver, crear, editar y publicar con
 * `speakers.manage` (`org_admin` y `organizer`); eliminar con `speakers.delete` (solo `org_admin`).
 * El `reviewer` no ve nada. Exige membresía activa en la organización del ponente o del evento.
 */
final class SpeakerPolicy
{
    use ChecksOrganizationPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, ['speakers.manage']);
    }

    public function view(User $user, Speaker $speaker): bool
    {
        return $this->allows($user, ['speakers.manage'], $speaker->organization_id);
    }

    public function create(User $user, Event $event): bool
    {
        return $this->allows($user, ['speakers.manage'], $event->organization_id);
    }

    /** Editar incluye publicar y despublicar. */
    public function update(User $user, Speaker $speaker): bool
    {
        return $this->allows($user, ['speakers.manage'], $speaker->organization_id);
    }

    public function delete(User $user, Speaker $speaker): bool
    {
        return $this->allows($user, ['speakers.delete'], $speaker->organization_id);
    }
}
