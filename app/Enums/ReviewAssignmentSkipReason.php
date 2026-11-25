<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Por qué una propuesta o una pareja propuesta-evaluador no se asignó (plan 011, CA-011-40 y 41). Lo
 * devuelve el informe de `AssignReviewers`: una operación nunca omite en silencio.
 */
enum ReviewAssignmentSkipReason: string
{
    /** La propuesta es de un conferencista invitado (`origin = invited`): no se evalúa (DA-5). */
    case NotFromCall = 'not_from_call';

    /** La propuesta no está `submitted` ni `under_review` (retirada o ya decidida). */
    case IneligibleStatus = 'ineligible_status';

    /** El correo del evaluador coincide con el del autor o de un participante (CA-011-7). */
    case ConflictOfInterest = 'conflict_of_interest';

    /** El evaluador ya tiene esta propuesta asignada (la operación es idempotente). */
    case AlreadyAssigned = 'already_assigned';

    /** La asignación se canceló antes y conserva una evaluación: reactivarla es decisión de F4 (DT-20). */
    case PreviouslyCancelled = 'previously_cancelled';

    /**
     * El evaluador de una regla de alcance ya no es integrante activo o perdió su asignación de evento
     * (F3b): se omite su regla y se informa; la regla se conserva para que alguien la revise o la quite.
     */
    case ReviewerUnavailable = 'reviewer_unavailable';

    public function label(): string
    {
        return __("review_assignments.skip_reasons.{$this->value}");
    }
}
