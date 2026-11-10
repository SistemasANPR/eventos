<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\User;
use App\Support\Auth\ChecksOrganizationPermissions;

/**
 * Quién puede qué con las convocatorias (plan 004, CA-004-5): consultar con
 * `calls.view` o `calls.manage`; crear, editar, publicar y cambiar el estado (pausar, cerrar, reabrir) con `calls.manage`.
 * Exige membresía activa en la organización en contexto y que la convocatoria
 * o el evento sean de ella: el permiso de un rol en A no sirve en B.
 *
 * No hay `delete`: una convocatoria con propuestas es evidencia; se cierra, no se borra.
 */
final class CallForSubmissionsPolicy
{
    use ChecksOrganizationPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, ['calls.view', 'calls.manage']);
    }

    public function view(User $user, CallForSubmissions $call): bool
    {
        return $this->allows($user, ['calls.view', 'calls.manage'], $call->organization_id);
    }

    public function create(User $user, Event $event): bool
    {
        return $this->allows($user, ['calls.manage'], $event->organization_id);
    }

    public function update(User $user, CallForSubmissions $call): bool
    {
        return $this->allows($user, ['calls.manage'], $call->organization_id);
    }

    public function publish(User $user, CallForSubmissions $call): bool
    {
        return $this->allows($user, ['calls.manage'], $call->organization_id);
    }

    /** Pausar, cerrar y reabrir a mano (H-004-4, CA-004-27). */
    public function changeStatus(User $user, CallForSubmissions $call): bool
    {
        return $this->allows($user, ['calls.manage'], $call->organization_id);
    }
}
