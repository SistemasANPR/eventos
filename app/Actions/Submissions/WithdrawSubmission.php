<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Enums\SubmissionStatus;
use App\Exceptions\Domain\SubmissionNotWithdrawable;
use App\Models\Submission;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * El autor retira su propuesta (plan 006, H-006-5, CA-006-46 a 49). No depende de que la convocatoria
 * esté abierta (DA-7): basta con que la propuesta siga `submitted`. Pasa a `withdrawn` con `withdrawn_at`;
 * no se borra (es evidencia) y el equipo la sigue viendo con ese estado.
 *
 * Idempotente: retirar una propuesta ya retirada no cambia nada ni envía otro correo (un doble clic o un
 * reintento dan el mismo resultado). La fila se bloquea en la transacción para que dos retiros simultáneos
 * no avisen dos veces. Los correos se encolan DESPUÉS de confirmar. Requiere el contexto de organización.
 *
 * @return bool `true` si la propuesta cambió de estado; `false` si ya estaba retirada
 *
 * @throws SubmissionNotWithdrawable si ya no está en estado `submitted` ni `withdrawn`
 */
final class WithdrawSubmission
{
    public function __construct(private readonly NotifySubmissionWithdrawn $notify) {}

    public function handle(Submission $submission): bool
    {
        $changed = DB::transaction(function () use ($submission): bool {
            $current = Submission::query()->lockForUpdate()->findOrFail($submission->id);

            if ($current->status === SubmissionStatus::Withdrawn) {
                return false;
            }

            if ($current->status !== SubmissionStatus::Submitted) {
                throw new SubmissionNotWithdrawable;
            }

            $current->forceFill([
                'status' => SubmissionStatus::Withdrawn,
                'withdrawn_at' => Date::now(),
                'version' => $current->version + 1,
            ])->save();

            // Sin datos personales: la propuesta y quién actuó (el autor, por su sesión).
            activity()
                ->performedOn($current)
                ->event('submission_withdrawn')
                ->withProperties(['organization_id' => $current->organization_id, 'event_id' => $current->event_id])
                ->log('submission_withdrawn');

            return true;
        });

        if ($changed) {
            $this->notify->handle($submission->refresh());
        }

        return $changed;
    }
}
