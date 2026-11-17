<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\SubmissionExports\DownloadSubmissionExport;
use App\Jobs\ExportSubmissionsJob;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

/**
 * Tarea programada: borra los archivos de exportación de propuestas con más de 24 horas (plan 009,
 * DA-3, CA-009-19). Dos fuentes, para no dejar nada: las exportaciones auditadas que ya vencieron y,
 * por si el job escribió el archivo y falló antes de auditar, cualquier archivo de `exports/` cuya
 * fecha de modificación sea anterior al plazo. El fallo de un archivo no detiene a los demás, pero el
 * comando termina con error para que se vea.
 */
final class PurgeSubmissionExportsCommand extends Command
{
    protected $signature = 'submission-exports:purge';

    protected $description = 'Borra los archivos de exportación de propuestas con más de 24 horas';

    public function handle(): int
    {
        $disk = Storage::disk((string) config('filesystems.private_uploads_disk'));
        $limit = CarbonImmutable::now()->subHours(DownloadSubmissionExport::FILE_HOURS);
        $deleted = 0;
        $failed = 0;

        $paths = Activity::query()
            ->where('event', 'submissions_exported')
            ->where('created_at', '<=', $limit)
            ->get()
            // Son rutas (cadenas), no modelos: colección base, o `unique()` espera modelos.
            ->toBase()
            ->map(static function (Activity $entry): ?string {
                $organizationId = $entry->properties['organization_id'] ?? null;
                $exportId = $entry->properties['export_id'] ?? null;
                $format = $entry->properties['format'] ?? null;

                return is_string($organizationId) && is_string($exportId) && is_string($format)
                    ? ExportSubmissionsJob::pathFor($organizationId, $exportId, $format)
                    : null;
            })
            ->filter();

        foreach ($disk->allFiles('organizations') as $file) {
            if (preg_match('#^organizations/[^/]+/exports/[^/]+$#', $file) === 1 && $disk->lastModified($file) <= $limit->getTimestamp()) {
                $paths->push($file);
            }
        }

        foreach ($paths->unique() as $path) {
            if (! $disk->exists($path)) {
                continue;
            }

            if ($disk->delete($path)) {
                $deleted++;
            } else {
                $failed++;
                $this->error("No se pudo borrar {$path}.");
            }
        }

        $this->line("Exportaciones vencidas eliminadas: {$deleted}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
