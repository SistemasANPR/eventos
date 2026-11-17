<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Event;
use App\Models\SubmissionType;
use App\Models\User;
use App\Support\Auth\ChecksOrganizationPermissions;

/**
 * Quién puede qué con los tipos de propuesta (plan 004, DA-7, CA-004-33): los
 * mismos permisos que las convocatorias. Consultar con `calls.view` o
 * `calls.manage`; crear, renombrar, mover y archivar con `calls.manage`. No hay
 * `delete`: un tipo en uso es evidencia; se archiva.
 */
final class SubmissionTypePolicy
{
    use ChecksOrganizationPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, ['calls.view', 'calls.manage']);
    }

    public function view(User $user, SubmissionType $type): bool
    {
        return $this->allows($user, ['calls.view', 'calls.manage'], $type->organization_id);
    }

    public function create(User $user, Event $event): bool
    {
        return $this->allows($user, ['calls.manage'], $event->organization_id);
    }

    public function update(User $user, SubmissionType $type): bool
    {
        return $this->allows($user, ['calls.manage'], $type->organization_id);
    }
}
