<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Event;
use App\Models\PrivacyNotice;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;

/**
 * Quién puede qué con el aviso de privacidad (plan 003, CA-003-18): todo con
 * `privacy_notices.manage`, solo `org_admin` en la matriz. Exige membresía
 * activa en la organización en contexto y que el evento o aviso sea de ella.
 *
 * No hay `delete`: una versión publicada es evidencia y no se borra (y un
 * borrador tampoco se borra, se edita).
 */
final class PrivacyNoticePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user);
    }

    public function view(User $user, PrivacyNotice $notice): bool
    {
        return $this->allows($user, $notice->organization_id);
    }

    public function create(User $user, Event $event): bool
    {
        return $this->allows($user, $event->organization_id);
    }

    public function update(User $user, PrivacyNotice $notice): bool
    {
        return $this->allows($user, $notice->organization_id);
    }

    public function publish(User $user, PrivacyNotice $notice): bool
    {
        return $this->allows($user, $notice->organization_id);
    }

    private function allows(User $user, ?string $organizationId = null): bool
    {
        if (! CurrentOrganization::has()) {
            return false;
        }

        $organization = CurrentOrganization::get();

        if ($organizationId !== null && $organizationId !== $organization->id) {
            return false;
        }

        return $user->hasActiveMembershipIn($organization) && $user->checkPermissionTo('privacy_notices.manage');
    }
}
