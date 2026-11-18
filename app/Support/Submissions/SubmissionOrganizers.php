<?php

declare(strict_types=1);

namespace App\Support\Submissions;

use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Collection;

/**
 * Quiénes del equipo reciben los avisos de propuestas (plan 006): integrantes ACTIVOS de la organización
 * en contexto con el permiso `submissions.view`. Una membresía desactivada o de otra organización no cuenta.
 */
final class SubmissionOrganizers
{
    /** @return Collection<int, User> */
    public static function current(): Collection
    {
        return User::query()
            ->activeMembersOf(CurrentOrganization::get())
            ->get()
            ->filter(static fn (User $user): bool => $user->checkPermissionTo('submissions.view'))
            ->values();
    }
}
