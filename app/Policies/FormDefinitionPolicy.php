<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Event;
use App\Models\FormDefinition;
use App\Models\User;
use App\Support\Auth\ChecksOrganizationPermissions;

/**
 * Quién puede qué con los formularios de propuestas (plan 005, §5, CA-005-6):
 * consultar, crear, editar y eliminar con `forms.manage`. Exige membresía activa
 * en la organización del formulario o del evento. El permiso `forms.view` (solo ver
 * la lista y usar la vista previa, CA-005-13) basta para `viewAny` y `view`; ningún rol
 * de la matriz lo trae todavía: se concede a mano hasta que un rol lo necesite.
 */
final class FormDefinitionPolicy
{
    use ChecksOrganizationPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, ['forms.view', 'forms.manage']);
    }

    public function view(User $user, FormDefinition $form): bool
    {
        return $this->allows($user, ['forms.view', 'forms.manage'], $form->organization_id);
    }

    /** Sin evento (el botón «Crear» del panel) basta el permiso; con evento, además que sea de la organización en contexto. */
    public function create(User $user, ?Event $event = null): bool
    {
        return $this->allows($user, ['forms.manage'], $event?->organization_id);
    }

    public function update(User $user, FormDefinition $form): bool
    {
        return $this->allows($user, ['forms.manage'], $form->organization_id);
    }

    public function delete(User $user, FormDefinition $form): bool
    {
        return $this->allows($user, ['forms.manage'], $form->organization_id);
    }
}
