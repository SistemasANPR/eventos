<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Enums\SubmissionFileStatus;
use App\Models\SubmissionFile;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Files\PrivateUploads;
use App\Support\Files\SubmissionFileData;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Emite la URL temporal firmada para que un organizador descargue un archivo de
 * propuesta (plan 007, H-007-2, DA-4). Exige la organización en contexto (la
 * fija la ruta del panel, nunca el cliente): el scope global hace que un archivo
 * de otra organización no exista (404). Un archivo `pending` tampoco existe.
 *
 * La URL caduca a los 5 minutos y fuerza `attachment` con un nombre construido
 * SOLO con el uuid y la extensión validada, nunca con `original_name` (CA-007-22).
 * Cada descarga y cada intento denegado quedan en la auditoría, sin contenido ni nombre.
 *
 * Nota: el driver `local` (solo desarrollo) sirve el archivo con su propia ruta
 * firmada y no aplica `ResponseContentDisposition`; staging y producción usan un
 * bucket S3 compatible, que sí lo aplica.
 *
 * @throws NotFoundHttpException archivo inexistente, de otra organización o `pending`
 * @throws AuthorizationException sin `files.download` o sin membresía activa (queda auditado)
 */
final class DownloadSubmissionFile
{
    use AuthorizesInOrganization;

    /** Vigencia de la URL firmada (DA-4). */
    public const URL_MINUTES = 5;

    public function __construct(private readonly PrivateUploads $uploads) {}

    public function handle(User $actor, string $fileId): string
    {
        $organizationId = CurrentOrganization::id();

        try {
            $file = SubmissionFile::query()->findOrFail($fileId);
        } catch (ModelNotFoundException) {
            throw new NotFoundHttpException;
        }

        // Sin propuesta no hay nada que descargar (CA-007-24): es 404 para todos, antes de autorizar.
        if ($file->status !== SubmissionFileStatus::Attached) {
            throw new NotFoundHttpException;
        }

        try {
            $this->authorizeActor($actor, 'download', $file);
        } catch (AuthorizationException $exception) {
            $this->audit($actor, $file, 'submission_file_download_denied', $organizationId);

            throw $exception;
        }

        $this->audit($actor, $file, 'submission_file_downloaded', $organizationId);

        return $this->uploads->temporaryUrl(
            $file->storage_path,
            now()->addMinutes(self::URL_MINUTES),
            $file->id.'.'.SubmissionFileData::extension($file),
        );
    }

    private function audit(User $actor, SubmissionFile $file, string $event, string $organizationId): void
    {
        activity()
            ->causedBy($actor)
            ->performedOn($file)
            ->event($event)
            ->withProperties(['organization_id' => $organizationId])
            ->log($event);
    }
}
