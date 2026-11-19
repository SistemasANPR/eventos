<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Event;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;

/**
 * Quién puede qué con los eventos (plan 003, matriz del plan 001): gestionar
 * con `events.manage`; publicar y archivar con `events.publish`. Exige además
 * una membresía activa en la organización en contexto, y que el evento sea de
 * esa organización: el permiso de un rol en A no sirve en B.
 */
final class EventPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'events.manage');
    }

    public function view(User $user, Event $event): bool
    {
        return $this->allows($user, 'events.manage', $event);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'events.manage');
    }

    public function update(User $user, Event $event): bool
    {
        return $this->allows($user, 'events.manage', $event);
    }

    public function publish(User $user, Event $event): bool
    {
        return $this->allows($user, 'events.publish', $event);
    }

    public function archive(User $user, Event $event): bool
    {
        return $this->allows($user, 'events.publish', $event);
    }

    /**
     * Landing del evento (plan 022, DA-10): reutiliza `events.manage`, sin permiso nuevo. Ver el borrador
     * y el editor, guardar y descartar los cambios, y actualizar (guardar y publicar) exigen lo mismo.
     * La versión publicada la ve cualquiera en el sitio público y no pasa por la Policy.
     */
    public function viewLanding(User $user, Event $event): bool
    {
        return $this->allows($user, 'events.manage', $event);
    }

    public function updateLanding(User $user, Event $event): bool
    {
        return $this->allows($user, 'events.manage', $event);
    }

    public function publishLanding(User $user, Event $event): bool
    {
        return $this->allows($user, 'events.manage', $event);
    }

    private function allows(User $user, string $permission, ?Event $event = null): bool
    {
        if (! CurrentOrganization::has()) {
            return false;
        }

        $organization = CurrentOrganization::get();

        if ($event instanceof Event && $event->organization_id !== $organization->id) {
            return false;
        }

        return $user->hasActiveMembershipIn($organization) && $user->checkPermissionTo($permission);
    }
}
