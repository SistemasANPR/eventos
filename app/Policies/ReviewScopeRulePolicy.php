<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ReviewScopeRule;
use App\Models\User;
use App\Support\Auth\ChecksOrganizationPermissions;

/**
 * Quién puede ver y quitar las reglas permanentes de alcance (plan 011, F3b, CA-011-43): las mismas
 * personas que asignan, con `review_assignments.manage`. Crear una regla es parte de asignar por eje o
 * convocatoria y lo autoriza `ReviewAssignmentPolicy::create`. Exige membresía activa en la organización
 * en contexto y que la regla sea de ella.
 */
final class ReviewScopeRulePolicy
{
    use ChecksOrganizationPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, ['review_assignments.manage']);
    }

    public function view(User $user, ReviewScopeRule $rule): bool
    {
        return $this->allows($user, ['review_assignments.manage'], $rule->organization_id);
    }

    public function delete(User $user, ReviewScopeRule $rule): bool
    {
        return $this->allows($user, ['review_assignments.manage'], $rule->organization_id);
    }
}
