<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Enums\SubmissionStatus;
use App\Exceptions\Domain\InvalidSubmissionTransition;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;

/**
 * Pasa una propuesta de `submitted` a `under_review` con historial y auditoría (plan 012, H-012-1; la usa
 * `AssignReviewers` del plan 011, DA-5, al recibir la propuesta su primera asignación).
 *
 * Contrato:
 * - Entrada: quien la marca (`$actor`, nulo si lo hace un trabajo programado) y la propuesta.
 * - Idempotente: si ya está `under_review` no cambia nada ni duplica el historial y devuelve `false`.
 * - Devuelve `true` solo si cambió el estado. Usa el estado ACTUAL de la base, no el de la instancia
 *   recibida, así que una instancia vieja no provoca un conflicto.
 * - Cualquier otro estado (decidida o retirada) lanza `InvalidSubmissionTransition`.
 * - No autoriza: la autorización es de la Action que la llama (la de asignar evaluadores). Por eso solo
 *   debe invocarse desde otra Action, nunca desde una ruta. Se ejecuta en la organización de la propuesta,
 *   con o sin contexto previo, y participa de la transacción de quien la llama.
 *
 * @throws InvalidSubmissionTransition si la propuesta no está `submitted` ni `under_review`
 */
final class MarkSubmissionUnderReview
{
    public function __construct(private readonly ChangeSubmissionStatus $change) {}

    public function handle(?User $actor, Submission $submission): bool
    {
        $organization = Organization::query()->findOrFail($submission->organization_id);

        return CurrentOrganization::run($organization, fn (): bool => DB::transaction(function () use ($actor, $submission): bool {
            $current = Submission::query()->lockForUpdate()->findOrFail($submission->id);

            if ($current->status === SubmissionStatus::UnderReview) {
                return false;
            }

            // Volver a `under_review` desde una decisión es una reversa (exige `submissions.revert`), no esto.
            if ($current->status !== SubmissionStatus::Submitted) {
                throw new InvalidSubmissionTransition($current->status, SubmissionStatus::UnderReview);
            }

            $this->change->handle($actor, $current, SubmissionStatus::UnderReview);

            return true;
        }));
    }
}
