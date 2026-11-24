<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\Auth\ChecksOrganizationPermissions;

/**
 * Quién fija los plazos de retención de la organización (plan 014, CA-014-35): `retention.manage`, por defecto
 * solo `org_admin`. Se autoriza contra la clase: los plazos son de la organización en contexto.
 */
final class RetentionPolicyPolicy
{
    use ChecksOrganizationPermissions;

    public function manage(User $user): bool
    {
        return $this->allows($user, ['retention.manage']);
    }
}
