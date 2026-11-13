<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MailTemplate;
use App\Models\User;
use App\Support\Auth\ChecksOrganizationPermissions;

/**
 * Quién puede qué con los correos de la organización (plan 008, §5, CA-008-13): ver el
 * catálogo con `mail-templates.view` (o `manage`); editar, restaurar y activar o desactivar
 * solo con `mail-templates.manage`. Todas exigen membresía activa en la organización en
 * contexto y se piden sobre la clase, porque el catálogo no es un modelo: la predeterminada
 * del sistema vive en el código y nadie la edita.
 */
final class MailTemplatePolicy
{
    use ChecksOrganizationPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, ['mail-templates.view', 'mail-templates.manage']);
    }

    public function update(User $user, ?MailTemplate $template = null): bool
    {
        return $this->allows($user, ['mail-templates.manage'], $template?->organization_id);
    }

    public function restore(User $user, ?MailTemplate $template = null): bool
    {
        return $this->allows($user, ['mail-templates.manage'], $template?->organization_id);
    }

    public function toggle(User $user): bool
    {
        return $this->allows($user, ['mail-templates.manage']);
    }
}
