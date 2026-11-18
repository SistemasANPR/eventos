<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\SubmissionExports\ExportSubmissions;
use App\Exceptions\Domain\SubmissionExportTooLarge;
use App\Exceptions\Domain\SubmissionExportUnavailable;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\SubmissionExport\ExportFilters;
use App\Support\SubmissionExport\SubmissionExportRows;
use App\Support\SubmissionExport\SubmissionsSheet;
use App\Support\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Activitylog\Models\Activity;
use Throwable;

/**
 * Genera el archivo de una exportación de propuestas (plan 009, H-009-3, DA-3). Corre en un worker,
 * SIN sesión y SIN contexto de organización: lo establece con `CurrentOrganization::run()` (CA-009-18).
 * Antes de escribir vuelve a comprobar el permiso del usuario, recalcula la consulta desde la base y
 * re-aplica el tope de filas: un fallo se ve (excepción y auditoría `submissions_export_failed`), nunca
 * un archivo vacío ni truncado.
 *
 * El archivo va al disco PRIVADO, en `organizations/{organization}/exports/{export}.{formato}`, con
 * nombre generado; una tarea programada lo borra a las 24 horas. Idempotente: el mismo `exportId` escribe
 * el mismo archivo y deja una sola entrada de auditoría. La auditoría no lleva el contenido exportado.
 */
final class ExportSubmissionsJob implements ShouldQueue
{
    use AuthorizesInOrganization, Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /**
     * @param  array<string, string>  $filters  ya validados por `ExportFilters::toArray()`
     */
    public function __construct(
        public readonly string $organizationId,
        public readonly string $eventId,
        public readonly string $userId,
        public readonly array $filters,
        public readonly string $format,
        public readonly string $exportId,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public static function pathFor(string $organizationId, string $exportId, string $format): string
    {
        return "organizations/{$organizationId}/exports/{$exportId}.{$format}";
    }

    public function handle(): void
    {
        $this->inOrganization(function (): void {
            if ($this->alreadyExported()) {
                return;
            }

            $user = User::query()->findOrFail($this->userId);
            $event = Event::query()->findOrFail($this->eventId);

            $this->authorizeActor($user, 'export', Submission::class);

            $query = ExportFilters::fromArray($this->filters, $event)->query();
            $total = (clone $query)->count();

            if ($total > ExportSubmissions::MAX_ROWS) {
                throw new SubmissionExportTooLarge($total, ExportSubmissions::MAX_ROWS);
            }

            $submissions = $query
                ->with(['call', 'submissionType', 'track', 'participants'])
                ->orderBy('submitted_at')
                ->orderBy('id')
                ->get();

            $table = SubmissionExportRows::build($submissions, $event);
            $path = self::pathFor($this->organizationId, $this->exportId, $this->format);

            $stored = Excel::store(
                new SubmissionsSheet($table['headings'], $table['rows']),
                $path,
                (string) config('filesystems.private_uploads_disk'),
                $this->format === 'csv' ? ExcelWriter::CSV : ExcelWriter::XLSX,
            );

            if ($stored !== true) {
                throw new SubmissionExportUnavailable;
            }

            activity()
                ->causedBy($user)
                ->event('submissions_exported')
                ->withProperties([
                    'export_id' => $this->exportId,
                    'organization_id' => $this->organizationId,
                    'event_id' => $this->eventId,
                    'filters' => $this->filters,
                    'rows' => $submissions->count(),
                    'format' => $this->format,
                    'path' => $path,
                ])
                ->log('submissions_exported');
        });
    }

    /** Agotados los intentos queda a la vista, sin datos personales. */
    public function failed(Throwable $exception): void
    {
        $this->inOrganization(function () use ($exception): void {
            $user = User::query()->find($this->userId);

            activity()
                ->causedBy($user)
                ->event('submissions_export_failed')
                ->withProperties([
                    'export_id' => $this->exportId,
                    'organization_id' => $this->organizationId,
                    'event_id' => $this->eventId,
                    'format' => $this->format,
                    'exception' => $exception::class,
                ])
                ->log('submissions_export_failed');

            Log::error('Exportación de propuestas fallida tras agotar los intentos.', [
                'export_id' => $this->exportId,
                'organization_id' => $this->organizationId,
                'exception' => $exception::class,
            ]);
        });
    }

    private function alreadyExported(): bool
    {
        return Activity::query()
            ->where('event', 'submissions_exported')
            ->where('properties->export_id', $this->exportId)
            ->exists();
    }

    /** @param  Closure(): void  $callback */
    private function inOrganization(Closure $callback): void
    {
        CurrentOrganization::run(Organization::query()->findOrFail($this->organizationId), function () use ($callback): bool {
            $callback();

            return true;
        });
    }
}
