<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Actions\Program\PromoteSubmissionToProgram;
use App\Enums\SubmissionStatus;
use App\Exceptions\Domain\InvalidSubmissionTransition;
use App\Exceptions\Domain\SubmissionStatusConflict;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Submissions\AuthorComment;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Acepta una propuesta (plan 012, H-012-1 y H-012-5, CA-012-1, 2, 5, 6 y 27 a 29), desde `under_review`,
 * `submitted` (eventos sin comité) o `waitlisted`. En la MISMA transacción la pasa al programa del evento
 * (`PromoteSubmissionToProgram`, plan 021): si eso falla, no queda aceptada. El correo no sale al decidir:
 * se avisa al publicar los resultados, salvo que la convocatoria ya los haya publicado (F3). `$submission->status` debe ser el que vio quien decide.
 *
 * @throws AuthorizationException si quien decide no tiene `submissions.decide` en la organización de la propuesta
 * @throws ValidationException si el comentario para el autor pasa de la longitud máxima
 * @throws InvalidSubmissionTransition si el estado no permite aceptar
 * @throws SubmissionStatusConflict si otra persona cambió el estado antes
 */
final class AcceptSubmission
{
    use AuthorizesInOrganization;

    public function __construct(
        private readonly ChangeSubmissionStatus $change,
        private readonly PromoteSubmissionToProgram $promote,
        private readonly PublishSubmissionResult $publishResult,
    ) {}

    public function handle(User $actor, Submission $submission, ?string $authorComment = null): Submission
    {
        $comment = AuthorComment::clean($authorComment);
        $organization = Organization::query()->findOrFail($submission->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $submission, $comment): Submission {
            $this->authorizeActor($actor, 'decide', $submission);

            $accepted = DB::transaction(function () use ($actor, $submission, $comment): Submission {
                $this->change->handle($actor, $submission, SubmissionStatus::Accepted, $comment);
                $this->promote->handle($actor, $submission);

                return Submission::query()->findOrFail($submission->id);
            });

            // Si la convocatoria ya publicó sus resultados, el aviso sale al decidir (CA-012-21).
            $this->publishResult->afterDecision($accepted);

            return Submission::query()->findOrFail($submission->id);
        });
    }
}
