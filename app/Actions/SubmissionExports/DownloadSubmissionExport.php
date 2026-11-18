<?php

declare(strict_types=1);

namespace App\Actions\SubmissionExports;

use App\Exceptions\Domain\SubmissionExportUnavailable;
use App\Jobs\ExportSubmissionsJob;
use App\Models\Submission;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Localiza el archivo de una exportación para entregárselo a quien la pidió (plan 009, CA-009-19).
 * Exige la organización en contexto (la fija la ruta) y el permiso `submissions.export`. La exportación
 * se identifica por su auditoría: solo existe para QUIEN LA PIDIÓ y dentro de su organización; para
 * cualquier otro, o si el archivo ya venció (24 horas), es 404.
 *
 * @throws AuthorizationException sin `submissions.export`
 * @throws NotFoundHttpException la exportación no existe, es de otro usuario u otra organización, o venció
 */
final class DownloadSubmissionExport
{
    use AuthorizesInOrganization;

    /** Vigencia del archivo; la tarea programada lo borra pasado este plazo. */
    public const FILE_HOURS = 24;

    /**
     * @return array{disk: string, path: string, name: string}
     */
    public function handle(User $actor, string $exportId): array
    {
        $organizationId = CurrentOrganization::id();

        $this->authorizeActor($actor, 'export', Submission::class);

        $entry = Activity::query()
            ->where('event', 'submissions_exported')
            ->where('causer_id', $actor->id)
            ->where('properties->export_id', $exportId)
            ->where('properties->organization_id', $organizationId)
            ->first();

        $format = $entry?->properties['format'] ?? null;

        if ($entry === null || ! is_string($format) || $entry->created_at === null || $entry->created_at->lte(now()->subHours(self::FILE_HOURS))) {
            throw new NotFoundHttpException;
        }

        // La ruta se reconstruye, nunca se toma tal cual de la auditoría.
        $path = ExportSubmissionsJob::pathFor($organizationId, $exportId, $format);
        $disk = (string) config('filesystems.private_uploads_disk');

        if (! Storage::disk($disk)->exists($path)) {
            throw new NotFoundHttpException(previous: new SubmissionExportUnavailable);
        }

        return ['disk' => $disk, 'path' => $path, 'name' => "propuestas-{$exportId}.{$format}"];
    }
}
