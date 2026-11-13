<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Submission;
use App\Models\User;
use App\Support\Auth\ChecksOrganizationPermissions;

/**
 * Quién puede qué con las propuestas en el panel del organizador (plan 009,
 * CA-009-5, CA-009-15): consultar con `submissions.view`; exportar con
 * `submissions.export`. Exige membresía activa en la organización de la propuesta.
 * Solo lectura: cambiar el estado es del plan 012 y evaluar, del plan 011.
 */
final class SubmissionPolicy
{
    use ChecksOrganizationPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, ['submissions.view']);
    }

    public function view(User $user, Submission $submission): bool
    {
        return $this->allows($user, ['submissions.view'], $submission->organization_id);
    }

    public function export(User $user): bool
    {
        return $this->allows($user, ['submissions.export']);
    }
}
