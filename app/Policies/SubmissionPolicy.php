<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CallForSubmissions;
use App\Models\DecisionBatch;
use App\Models\Submission;
use App\Models\User;
use App\Support\Auth\ChecksOrganizationPermissions;

/**
 * Quién puede qué con las propuestas en el panel del organizador (plan 009,
 * CA-009-5, CA-009-15): consultar con `submissions.view`; exportar con
 * `submissions.export`. Exige membresía activa en la organización de la propuesta.
 * Decidir (aceptar, rechazar, lista de espera) con `submissions.decide` y revertir con `submissions.revert`
 * (plan 012); evaluar es del plan 011.
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

    /** Aceptar, rechazar o poner en lista de espera (plan 012, CA-012-1, 2 y 5): `submissions.decide`. */
    public function decide(User $user, Submission $submission): bool
    {
        return $this->allows($user, ['submissions.decide'], $submission->organization_id);
    }

    /** Revertir una decisión (plan 012, DA-1, CA-012-3): `submissions.revert`, por defecto solo `org_admin`. */
    public function revert(User $user, Submission $submission): bool
    {
        return $this->allows($user, ['submissions.revert'], $submission->organization_id);
    }

    /**
     * ¿Puede el usuario decidir (o publicar resultados) en la organización en contexto? Solo para mostrar u
     * ocultar las acciones masivas; cada Action vuelve a autorizar sobre las propuestas (plan 012, F2).
     */
    public function decideMany(User $user): bool
    {
        return $this->allows($user, ['submissions.decide']);
    }

    /** ¿Puede el usuario revertir en la organización en contexto? Solo para mostrar u ocultar la reversa por lote. */
    public function revertMany(User $user): bool
    {
        return $this->allows($user, ['submissions.revert']);
    }

    /**
     * Publicar los resultados de una convocatoria (plan 012, F2, CA-012-19): `submissions.decide`. Se invoca
     * con `[Submission::class, $convocatoria]`.
     */
    public function publishResults(User $user, CallForSubmissions $call): bool
    {
        return $this->allows($user, ['submissions.decide'], $call->organization_id);
    }

    /**
     * Revertir un lote de decisión masiva (plan 012, F2, CA-012-12): `submissions.revert`. Se invoca con
     * `[Submission::class, $lote]`.
     */
    public function revertBatch(User $user, DecisionBatch $batch): bool
    {
        return $this->allows($user, ['submissions.revert'], $batch->organization_id);
    }
}
