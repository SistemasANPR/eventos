<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Reviews\SyncScopedAssignments;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Submission;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Aplica las reglas permanentes de alcance a una propuesta recién enviada (plan 011, F3b, CA-011-42). Lo
 * encola `SubmitSubmission` al crear la propuesta. Idempotente: `SyncScopedAssignments` no duplica nada, así
 * que un reintento de la cola es inofensivo. Sin correo (DA-8). Un fallo no se traga: la cola reintenta y,
 * agotados los intentos, queda en `failed_jobs`; la propuesta ya está recibida y el botón manual
 * «Asignar propuestas nuevas» recupera cualquier asignación que no se hizo.
 */
final class SyncScopedAssignmentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $organizationId,
        public readonly string $submissionId,
    ) {}

    /** @return list<int> segundos de espera entre intentos */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(SyncScopedAssignments $sync): void
    {
        CurrentOrganization::run(Organization::query()->findOrFail($this->organizationId), function () use ($sync): bool {
            $submission = Submission::query()->find($this->submissionId);

            // Una propuesta borrada entre el envío y el job ya no tiene nada que asignar.
            if ($submission instanceof Submission) {
                $sync->handle(null, Event::query()->findOrFail($submission->event_id), $submission);
            }

            return true;
        });
    }
}
