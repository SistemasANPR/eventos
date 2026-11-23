<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Actions\Program\HideProgramOfSubmission;
use App\Enums\SubmissionStatus;
use App\Exceptions\Domain\InvalidSubmissionTransition;
use App\Exceptions\Domain\PublishedDecisionNeedsConfirmation;
use App\Exceptions\Domain\SubmissionStatusConflict;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Program\ProgramReversal;
use App\Support\Submissions\AuthorComment;
use App\Support\Submissions\SubmissionTransitions;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Revierte una decisión: `accepted`, `waitlisted` o `rejected` vuelven a `under_review` conservando el
 * historial (plan 012, DA-1, DA-4, CA-012-3, 5, 6 y 8). Exige `submissions.revert` (por defecto solo
 * `org_admin`). Si el resultado ya se publicó, exige `$confirmedPublished`: la pantalla advierte que el
 * autor ya fue avisado. La reversa NUNCA envía correo.
 *
 * Revertir una aceptada oculta, en la misma transacción, lo que pasó al programa
 * (`HideProgramOfSubmission`, plan 021); el `ProgramReversal` devuelto lleva el aviso de cuántos ponentes
 * y qué actividad se ocultaron (sin cambios si la decisión revertida no era una aceptación).
 * `$reason` es un motivo interno opcional que queda en el historial. `$submission->status` debe ser el
 * que vio quien revierte.
 *
 * @throws AuthorizationException si quien revierte no tiene `submissions.revert` en la organización de la propuesta
 * @throws ValidationException si el motivo pasa de la longitud máxima
 * @throws InvalidSubmissionTransition si la propuesta no está en un estado decidido
 * @throws SubmissionStatusConflict si otra persona cambió el estado antes
 * @throws PublishedDecisionNeedsConfirmation si el resultado ya se publicó y no se confirmó
 */
final class RevertSubmissionDecision
{
    use AuthorizesInOrganization;

    public function __construct(
        private readonly ChangeSubmissionStatus $change,
        private readonly HideProgramOfSubmission $hideProgram,
    ) {}

    public function handle(User $actor, Submission $submission, ?string $reason = null, bool $confirmedPublished = false): ProgramReversal
    {
        $reason = AuthorComment::clean($reason, 'reason');
        $organization = Organization::query()->findOrFail($submission->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $submission, $reason, $confirmedPublished): ProgramReversal {
            $this->authorizeActor($actor, 'revert', $submission);

            $from = $submission->status;

            // `submitted` → `under_review` está en la tabla pero es la marca de revisión, no una reversa.
            if (! SubmissionTransitions::isReversal($from, SubmissionStatus::UnderReview)) {
                throw new InvalidSubmissionTransition($from, SubmissionStatus::UnderReview);
            }

            return DB::transaction(function () use ($actor, $submission, $reason, $confirmedPublished, $from): ProgramReversal {
                $this->change->handle($actor, $submission, SubmissionStatus::UnderReview, null, $reason, $confirmedPublished);

                return $from === SubmissionStatus::Accepted
                    ? $this->hideProgram->handle($actor, $submission)
                    : new ProgramReversal(null, false, 0, 0);
            });
        });
    }
}
