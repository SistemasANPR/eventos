<?php

declare(strict_types=1);

namespace App\Support\Reviews;

use App\Enums\EventAssignmentRole;
use App\Enums\ReviewAssignmentStatus;
use App\Models\EventAssignment;
use App\Models\ReviewAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Única consulta de las propuestas asignadas a un evaluador (plan 011, H-011-3, CA-011-11 y 12). Todo lo
 * que el panel del evaluador lee de sus asignaciones (el tablero, la lista y el detalle) sale de aquí, de
 * modo que una regla de acceso o de ocultamiento se aplica en UN solo lugar. La revisión ciega (CA-011-18,
 * 19 y 54) vive en el servidor, no en cada pantalla: esta consulta no une ni carga a los participantes, así
 * que el tablero y la lista no llevan datos de autor; el detalle los pide a `SubmissionDetail::forReviewer`,
 * que consulta `BlindReview`, y la descarga la gobierna `SubmissionFilePolicy` con la misma regla.
 *
 * Una asignación entra solo si es del propio evaluador, no está cancelada (CA-011-53), su propuesta sigue
 * existiendo y el evaluador conserva su asignación de evento de rol `reviewer` en el evento de la propuesta
 * (CA-011-28). Exige la organización en contexto: la acota el scope global. Un identificador que no sale de
 * esta consulta no existe para el evaluador: 404.
 */
final class ReviewerAssignments
{
    /**
     * Orden de la lista: las pendientes primero, luego las que están en curso, las enviadas y las de conflicto.
     * Las pendientes y en curso son lo que hay por hacer (CA-011-51).
     */
    private const PRIORITY_SQL = "CASE review_assignments.status WHEN 'pending' THEN 0 WHEN 'in_progress' THEN 1 WHEN 'submitted' THEN 2 ELSE 3 END";

    /** @return Builder<ReviewAssignment> */
    public static function for(User $reviewer): Builder
    {
        return ReviewAssignment::query()
            ->where('reviewer_id', $reviewer->id)
            ->where('status', '!=', ReviewAssignmentStatus::Cancelled)
            ->whereIn('event_id', EventAssignment::query()
                ->where('user_id', $reviewer->id)
                ->where('role', EventAssignmentRole::Reviewer)
                ->select('event_id'))
            ->whereHas('submission');
    }

    /**
     * Pendientes primero; dentro de cada estado, la asignación más reciente primero.
     *
     * @param  Builder<ReviewAssignment>  $query
     * @return Builder<ReviewAssignment>
     */
    public static function pendingFirst(Builder $query): Builder
    {
        return $query->orderByRaw(self::PRIORITY_SQL)->orderByDesc('review_assignments.assigned_at');
    }
}
