<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\Auth\ChecksOrganizationPermissions;

/**
 * Quién puede exportar o anonimizar los datos de una persona de la organización (plan 014, H-014-4,
 * CA-014-30): `personal_data.manage`, por defecto solo `org_admin`. Se autoriza contra la clase: la persona
 * se localiza por su correo dentro de la organización en contexto, así que la de otra organización no existe.
 */
final class ContactPolicy
{
    use ChecksOrganizationPermissions;

    public function export(User $user): bool
    {
        return $this->allows($user, ['personal_data.manage']);
    }

    public function anonymize(User $user): bool
    {
        return $this->allows($user, ['personal_data.manage']);
    }
}
