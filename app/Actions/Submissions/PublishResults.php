<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Models\CallForSubmissions;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Submissions\PendingResults;
use App\Support\Submissions\PublishResultsReport;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Publica los resultados de una convocatoria (plan 012, F2, H-012-3, DA-2, CA-012-14, 15, 19, 20 y 22):
 * fija `results_published_at` de la convocatoria y avisa a cada autor con una propuesta decidida
 * (`PublishSubmissionResult`). Es la ÚNICA forma de avisar resultados en lote: decidir no envía nada
 * mientras la convocatoria no esté publicada (CA-012-20), con o sin fecha de resultados.
 *
 * Exige `submissions.decide` en la organización de la convocatoria (la Policy `publishResults`): sin el
 * permiso no cambia nada ni sale ningún correo (CA-012-19); una convocatoria de otra organización no
 * se publica (CA-012-22: la pantalla la resuelve con el filtro de organización y responde 404).
 *
 * Idempotente (CA-012-15): publicar de nuevo solo toca las propuestas aún sin publicar (las que se decidieron
 * después o cuyo aviso falló) y nunca repite un correo a un autor por el mismo resultado. Un fallo al avisar
 * una propuesta no deshace la publicación ni las demás: se cuenta en el informe, se registra y esa propuesta
 * queda pendiente para la siguiente publicación (CA-012-18).
 *
 * @throws AuthorizationException si quien publica no tiene `submissions.decide` en la organización de la convocatoria
 */
final class PublishResults
{
    use AuthorizesInOrganization;

    public function __construct(private readonly PublishSubmissionResult $publishResult) {}

    public function handle(User $actor, CallForSubmissions $call): PublishResultsReport
    {
        $organization = Organization::query()->findOrFail($call->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $call): PublishResultsReport {
            $this->authorizeActor($actor, 'publishResults', Submission::class, $call);

            $alreadyPublished = $this->markPublished($actor, $call);
            $published = 0;
            $failed = 0;

            // `lazyById`: la propia publicación saca a cada propuesta del filtro, así que no sirve paginar por desplazamiento.
            PendingResults::query($call)->lazyById(100)->each(function (Submission $submission) use (&$published, &$failed): void {
                try {
                    $published += $this->publishResult->handle($submission) ? 1 : 0;
                } catch (Throwable $exception) {
                    $failed++;
                    report($exception);
                    Log::error('No se pudo publicar el resultado de una propuesta; queda pendiente.', [
                        'submission_id' => $submission->id,
                        'exception' => $exception::class,
                    ]);
                }
            });

            return new PublishResultsReport($published, $failed, $alreadyPublished);
        });
    }

    /** @return bool verdadero si la convocatoria ya estaba publicada antes de esta llamada */
    private function markPublished(User $actor, CallForSubmissions $call): bool
    {
        return DB::transaction(function () use ($actor, $call): bool {
            $current = CallForSubmissions::query()->lockForUpdate()->findOrFail($call->id);

            if ($current->results_published_at !== null) {
                return true;
            }

            $current->forceFill(['results_published_at' => now()])->save();

            activity()
                ->causedBy($actor)
                ->performedOn($current)
                ->event('call_results_published')
                ->withProperties([
                    'organization_id' => $current->organization_id,
                    'event_id' => $current->event_id,
                    'call_id' => $current->id,
                ])
                ->log('call_results_published');

            return false;
        });
    }
}
