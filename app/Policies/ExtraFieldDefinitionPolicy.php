<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Event;
use App\Models\ExtraFieldDefinition;
use App\Models\User;
use App\Support\Auth\ChecksOrganizationPermissions;

/**
 * Quién puede definir las preguntas del formulario de hospedajes o de patrocinadores (plan 030, DA-7): quien administra
 * ese módulo (`lodgings.manage` o `sponsors.manage`: `org_admin` y `organizer`). Exige membresía activa en la organización
 * del evento.
 */
final class ExtraFieldDefinitionPolicy
{
    use ChecksOrganizationPermissions;

    public function manage(User $user, Event $event, string $scope): bool
    {
        $permission = match ($scope) {
            ExtraFieldDefinition::SCOPE_LODGING => 'lodgings.manage',
            ExtraFieldDefinition::SCOPE_SPONSOR => 'sponsors.manage',
            default => null,
        };

        return $permission !== null && $this->allows($user, [$permission], $event->organization_id);
    }
}
