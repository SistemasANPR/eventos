<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Enums\SubmissionFileStatus;
use App\Exceptions\Domain\OrphanFilesPurgeFailed;
use App\Models\Organization;
use App\Models\SubmissionFile;
use App\Support\Files\PrivateUploads;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Date;

/**
 * Borra los archivos `pending` de UNA organización que llevan más de 24 horas sin
 * ligarse a una propuesta (plan 007, H-007-3, DA-6): primero el contenido del disco
 * y luego marca la ficha como eliminada. Nunca toca los `attached`.
 *
 * Idempotente: lo ya eliminado no vuelve a consultarse y un archivo que ya no está
 * en el disco cuenta como borrado. Si el disco falla, la ficha queda `pending` (se
 * reintenta en la siguiente corrida) y se lanza `OrphanFilesPurgeFailed`.
 */
final class PurgeOrphanSubmissionFiles
{
    public const MAX_AGE_HOURS = 24;

    public function __construct(private readonly PrivateUploads $uploads) {}

    /**
     * @return int cantidad de archivos eliminados en esta corrida
     *
     * @throws OrphanFilesPurgeFailed si el disco no borra un archivo
     */
    public function handle(Organization $organization): int
    {
        return CurrentOrganization::run($organization, function () use ($organization): int {
            $purged = 0;

            SubmissionFile::query()
                ->where('status', SubmissionFileStatus::Pending)
                ->where('uploaded_at', '<', Date::now()->subHours(self::MAX_AGE_HOURS))
                ->each(function (SubmissionFile $file) use ($organization, &$purged): void {
                    if (! $this->uploads->deleteConfirmed($file->storage_path)) {
                        throw OrphanFilesPurgeFailed::onDisk($this->uploads->diskName(), $organization->id);
                    }

                    $file->delete();
                    $purged++;
                });

            if ($purged > 0) {
                // Solo el conteo y la organización: sin nombres de archivo ni datos personales.
                activity()
                    ->event('submission_files_purged')
                    ->withProperties(['organization_id' => $organization->id, 'count' => $purged])
                    ->log('submission_files_purged');
            }

            return $purged;
        });
    }
}
