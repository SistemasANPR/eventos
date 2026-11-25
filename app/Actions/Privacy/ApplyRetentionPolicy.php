<?php

declare(strict_types=1);

namespace App\Actions\Privacy;

use App\Enums\RetentionCategory;
use App\Enums\SubmissionStatus;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\MailDispatch;
use App\Models\Organization;
use App\Models\RetentionPolicy;
use App\Models\Review;
use App\Models\ReviewAnswer;
use App\Models\Speaker;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\SubmissionParticipant;
use App\Support\Privacy\PersonDataEraser;
use App\Support\Privacy\Redactor;
use App\Support\Privacy\RetentionReport;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Anonimiza o elimina lo que superó el plazo de retención de cada categoría de una organización (plan 014,
 * H-014-6, CA-014-33 a CA-014-36). Es lo que ejecuta el trabajo programado `retention:apply`, organización por
 * organización con `CurrentOrganization::run()`.
 *
 * **Denegar por defecto:** una categoría SIN plazo (sin fila en `retention_policies`) no se toca, y una
 * organización sin ningún plazo no hace nada ni se audita. Los plazos no tienen valor por defecto: los fija la
 * organización (`[PENDIENTE: plazos]`). Cada categoría actúa solo sobre sus datos y de ninguna otra:
 *
 * - `unaccepted_submissions`: propuestas rechazadas o retiradas desde hace más del plazo (desde la decisión o el
 *   retiro; si no consta, desde el envío): se anonimiza a sus autores (contacto incluido, si ya no tiene otros
 *   datos vivos), se borran sus archivos, se invalidan sus enlaces y se sustituyen los comentarios de la decisión.
 *   Se conserva el contenido de la propuesta (no es dato de la persona), salvo su nombre y su correo.
 * - `reviews`: el texto de los comentarios de evaluaciones de hace más del plazo; las calificaciones se conservan.
 * - `mail_dispatches`: envíos de correo de hace más del plazo, desvinculados de su destinatario.
 * - `audit_log`: registros de `activity_log` de la organización de hace más del plazo, eliminados.
 * - `backups`: solo declarativa; ningún trabajo borra respaldos.
 *
 * Idempotente y reanudable: cada propuesta y cada bloque de evaluaciones van en su transacción, y lo ya
 * anonimizado no se vuelve a seleccionar, así que un fallo a mitad se reintenta sin repetir ni dejar nada a medias
 * (CA-014-34). Un fallo de una categoría se reporta y no detiene a las demás; el informe lo lista para que el
 * comando termine con error. Audita `retention_applied` con los totales, sin datos personales.
 */
final class ApplyRetentionPolicy
{
    private const CHUNK = 100;

    public function __construct(private readonly PersonDataEraser $eraser) {}

    public function handle(Organization $organization): RetentionReport
    {
        return CurrentOrganization::run($organization, fn (): RetentionReport => $this->apply($organization));
    }

    private function apply(Organization $organization): RetentionReport
    {
        /** @var array<string, int> $days */
        $days = RetentionPolicy::query()->get()
            ->filter(fn (RetentionPolicy $policy): bool => $policy->category->isActionable())
            ->mapWithKeys(fn (RetentionPolicy $policy): array => [$policy->category->value => $policy->retention_days])
            ->all();

        if ($days === []) {
            return new RetentionReport;
        }

        $counts = [];
        $failed = [];

        // El registro de auditoría va el último: lo que hacen las demás categorías también se audita.
        foreach ([RetentionCategory::UnacceptedSubmissions, RetentionCategory::Reviews, RetentionCategory::MailDispatches, RetentionCategory::AuditLog] as $category) {
            if (! isset($days[$category->value])) {
                continue;
            }

            $cutoff = CarbonImmutable::now()->subDays($days[$category->value]);

            try {
                [$counts[$category->value], $failures] = match ($category) {
                    RetentionCategory::UnacceptedSubmissions => $this->unacceptedSubmissions($cutoff),
                    RetentionCategory::Reviews => [$this->reviews($cutoff), 0],
                    RetentionCategory::MailDispatches => [$this->mailDispatches($cutoff), 0],
                    RetentionCategory::AuditLog => [$this->auditLog($organization, $cutoff), 0],
                };

                if ($failures > 0) {
                    $failed[] = $category->value;
                }
            } catch (Throwable $exception) {
                report($exception);
                $counts[$category->value] = 0;
                $failed[] = $category->value;
            }
        }

        $report = new RetentionReport($counts, $failed);

        activity()
            ->event('retention_applied')
            ->withProperties(['organization_id' => $organization->id, ...$report->toArray()])
            ->log('retention_applied');

        return $report;
    }

    /** @return array{0: int, 1: int} propuestas anonimizadas y propuestas que fallaron */
    private function unacceptedSubmissions(CarbonImmutable $cutoff): array
    {
        $done = 0;
        $failures = 0;

        Submission::withTrashed()
            ->whereIn('status', [SubmissionStatus::Rejected->value, SubmissionStatus::Withdrawn->value])
            // Desde la decisión o el retiro; si no consta ninguno, desde el envío.
            ->whereRaw(
                'COALESCE(submissions.withdrawn_at, (SELECT MAX(sc.created_at) FROM submission_status_changes sc WHERE sc.submission_id = submissions.id AND sc.organization_id = submissions.organization_id), submissions.submitted_at) < ?',
                [$cutoff],
            )
            // Solo las que aún conservan datos personales.
            ->where(fn ($query) => $query
                ->whereIn('id', SubmissionParticipant::query()->whereNull('anonymized_at')->select('submission_id'))
                ->orWhereIn('id', SubmissionFile::withTrashed()->whereNotNull('submission_id')->whereNull('anonymized_at')->select('submission_id')))
            ->chunkById(self::CHUNK, function ($submissions) use (&$done, &$failures): void {
                foreach ($submissions as $submission) {
                    try {
                        $this->eraseSubmission($submission);
                        $done++;
                    } catch (Throwable $exception) {
                        // Una propuesta que falla (un archivo que el disco no borra) no detiene a las demás: se reintenta mañana.
                        report($exception);
                        $failures++;
                    }
                }
            });

        return [$done, $failures];
    }

    private function eraseSubmission(Submission $submission): void
    {
        $participants = SubmissionParticipant::query()->where('submission_id', $submission->id)->whereNull('anonymized_at')->get(['id', 'contact_id', 'full_name', 'email']);
        $needles = Redactor::needles([...$participants->pluck('email')->all(), ...$participants->pluck('full_name')->all()]);

        // Primero el disco: si falla, la base no cambia y se reintenta.
        $this->eraser->deleteBlobs([$submission->id], []);

        DB::transaction(function () use ($submission, $participants, $needles): void {
            $this->eraser->eraseFiles([$submission->id]);
            $this->eraser->invalidateAccessTokens([$submission->id]);
            $this->eraser->eraseDecisionComments([$submission->id]);
            $this->eraser->redactSubmissionTexts([$submission->id], $needles);
            $this->eraser->redactReviewComments([$submission->id], $needles);
            $this->eraser->eraseParticipants($participants->pluck('id')->all());
            $this->eraser->eraseContacts($this->contactsWithoutOtherData($participants->pluck('contact_id')->unique()->values()->all()));
        });
    }

    /**
     * De estos contactos, los que ya no tienen participantes ni ponentes con datos personales vivos.
     *
     * @param  list<string>  $contactIds
     * @return list<string>
     */
    private function contactsWithoutOtherData(array $contactIds): array
    {
        return Contact::query()
            ->whereIn('id', $contactIds)
            ->whereNotIn('id', SubmissionParticipant::query()->whereNull('anonymized_at')->select('contact_id'))
            ->whereNotIn('id', Speaker::withTrashed()->whereNull('anonymized_at')->whereNotNull('contact_id')->select('contact_id'))
            ->pluck('id')
            ->all();
    }

    /** @return int comentarios (respuestas e historiales) cuyo texto se sustituyó */
    private function reviews(CarbonImmutable $cutoff): int
    {
        $count = 0;

        Review::withTrashed()
            ->whereRaw('COALESCE(reviews.submitted_at, reviews.created_at) < ?', [$cutoff])
            ->whereIn('id', ReviewAnswer::query()->whereNotNull('value_text')->where('value_text', '<>', Redactor::TEXT)->select('review_id'))
            ->chunkById(self::CHUNK, function ($reviews) use (&$count): void {
                DB::transaction(function () use ($reviews, &$count): void {
                    $count += $this->eraser->eraseReviewComments($reviews->pluck('id')->all());
                });
            });

        return $count;
    }

    /** @return int envíos desvinculados de su destinatario */
    private function mailDispatches(CarbonImmutable $cutoff): int
    {
        return $this->eraser->anonymizeDispatches(MailDispatch::query()->where('created_at', '<', $cutoff));
    }

    /** @return int registros eliminados */
    private function auditLog(Organization $organization, CarbonImmutable $cutoff): int
    {
        return Activity::query()
            ->where('organization_id', $organization->id)
            ->where('created_at', '<', $cutoff)
            ->delete();
    }
}
